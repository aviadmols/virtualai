<?php

namespace Tests\Feature\Widget;

use App\Domain\Club\ClubVerification;
use App\Mail\ClubVerificationCodeMail;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 2a — the Customer-Club email one-time-code endpoints
 * (POST /widget/v1/club/request-code + /verify-code).
 *
 * Proves: request-code issues + emails a code and throttles a rapid repeat; verify-code
 * happy path stamps verified_at + opts the member into marketing + returns the member
 * block; bad/expired/over-cap codes are TYPED failures (never a 500); a failed guess
 * mints no lead; and account B can never verify against account A's site (isolation).
 */
final class WidgetClubAuthTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    // === CONSTANTS ===
    private const REQUEST_ENDPOINT = '/widget/v1/club/request-code';

    private const VERIFY_ENDPOINT = '/widget/v1/club/verify-code';

    private const ANON = 'anon_club_1234567890';

    private const EMAIL = 'shopper@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_request_code_issues_and_emails_a_code(): void
    {
        Mail::fake();
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::REQUEST_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL]);

        $response->assertOk()->assertExactJson(['ok' => true, 'code_sent' => true]);

        Mail::assertSent(ClubVerificationCodeMail::class, function (ClubVerificationCodeMail $mail) {
            return $mail->hasTo(self::EMAIL) && preg_match('/^\d{6}$/', $mail->code) === 1;
        });
    }

    public function test_request_code_throttles_a_rapid_repeat(): void
    {
        Mail::fake();
        $ctx = $this->makeSiteContext();
        $headers = $this->widgetHeaders($ctx['site'], $ctx['origin']);
        $body = ['anon_token' => self::ANON, 'email' => self::EMAIL];

        // First request sends; second (inside the throttle window) is refused, no email.
        $this->withHeaders($headers)->postJson(self::REQUEST_ENDPOINT, $body)
            ->assertOk()->assertJson(['ok' => true, 'code_sent' => true]);

        $this->withHeaders($headers)->postJson(self::REQUEST_ENDPOINT, $body)
            ->assertOk()->assertExactJson(['ok' => true, 'code_sent' => false, 'reason' => 'throttled']);

        Mail::assertSent(ClubVerificationCodeMail::class, 1);
    }

    public function test_verify_code_happy_path_stamps_verified_at_and_returns_member(): void
    {
        Mail::fake();
        $ctx = $this->makeSiteContext();
        $code = $this->issueCodeCapturingIt($ctx['site'], $ctx['origin']);

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => $code]);

        $response->assertOk()->assertExactJson([
            'ok' => true,
            'verified' => true,
            'member' => ['verified' => true],
        ]);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $this->assertNotNull($endUser);
        $this->assertTrue($endUser->isClubMember());
        $this->assertNotNull($endUser->verified_at);
        $this->assertSame(self::EMAIL, $endUser->email);
        // Joining the club is the explicit marketing opt-in (with a timestamp).
        $this->assertTrue($endUser->hasMarketingConsent());
        $this->assertNotNull($endUser->marketing_consent_at);

        // A club_joined activity trace was written for the shopper.
        $joined = Tenant::run($ctx['account'], fn () => ActivityEvent::query()
            ->where('subject_type', EndUser::class)
            ->where('subject_id', $endUser->getKey())
            ->where('kind', ActivityEvent::KIND_CLUB_JOINED)
            ->first());
        $this->assertNotNull($joined);
        $this->assertSame(ActivityEvent::ACTOR_END_USER, $joined->actor);
    }

    public function test_verify_code_with_a_wrong_code_is_a_typed_invalid_not_a_500(): void
    {
        Mail::fake();
        $ctx = $this->makeSiteContext();
        $this->issueCodeCapturingIt($ctx['site'], $ctx['origin']);

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => '000001']);

        $response->assertOk()->assertExactJson(['ok' => true, 'verified' => false, 'reason' => 'invalid']);

        // A failed guess mints NO lead (a code request/verify is not yet a lead).
        $this->assertNull($this->endUserFor($ctx['account'], $ctx['site'], self::ANON));
    }

    public function test_verify_code_with_no_pending_code_is_typed_expired(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => '123456']);

        $response->assertOk()->assertExactJson(['ok' => true, 'verified' => false, 'reason' => 'expired']);
    }

    public function test_verify_code_locks_after_the_attempt_cap(): void
    {
        Mail::fake();
        $ctx = $this->makeSiteContext();
        $this->issueCodeCapturingIt($ctx['site'], $ctx['origin']);
        $headers = $this->widgetHeaders($ctx['site'], $ctx['origin']);

        // Five wrong attempts: the first four are 'invalid', the fifth burns the code -> 'locked'.
        for ($i = 0; $i < 4; $i++) {
            $this->withHeaders($headers)
                ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => '000000'])
                ->assertOk()->assertJson(['verified' => false, 'reason' => 'invalid']);
        }

        $this->withHeaders($headers)
            ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => '000000'])
            ->assertOk()->assertExactJson(['ok' => true, 'verified' => false, 'reason' => 'locked']);

        // The code is burned: even the RIGHT code now reports expired (must request a new one).
        $this->withHeaders($headers)
            ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => '000000'])
            ->assertOk()->assertJson(['verified' => false, 'reason' => 'expired']);
    }

    public function test_a_missing_field_is_a_typed_422_json_not_html(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::REQUEST_ENDPOINT, ['anon_token' => self::ANON]);   // no email

        $response->assertStatus(422)->assertJsonStructure(['message']);
        $response->assertHeader('content-type', 'application/json');
    }

    public function test_club_verification_is_account_isolated_b_cannot_verify_a(): void
    {
        Mail::fake();
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');

        // A code is issued against SITE A for this token+email.
        $code = $this->issueCodeCapturingIt($a['site'], $a['origin']);

        // Presenting A's code to SITE B (its own bound tenant, keyed by B's site_id) does
        // not verify — B has no pending code for this token -> typed expired, no member.
        $this->withHeaders($this->widgetHeaders($b['site'], $b['origin']))
            ->postJson(self::VERIFY_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL, 'code' => $code])
            ->assertOk()->assertJson(['ok' => true, 'verified' => false, 'reason' => 'expired']);

        // No end user was created under B; A still has no member (never verified on A here).
        $this->assertNull($this->endUserFor($b['account'], $b['site'], self::ANON));

        // And B cannot see any club_joined activity at all.
        Tenant::run($b['account'], function (): void {
            $this->assertSame(0, ActivityEvent::query()->where('kind', ActivityEvent::KIND_CLUB_JOINED)->count());
        });
    }

    /**
     * Issue a code via the real endpoint, then read the exact 6-digit code out of the
     * captured mailable so the test can submit it. Keeps the OTP protocol opaque to the
     * test (no reaching into the cache internals).
     */
    private function issueCodeCapturingIt(\App\Models\Site $site, string $origin): string
    {
        $captured = null;

        Mail::assertNothingSent();   // clean slate for this issue

        $this->withHeaders($this->widgetHeaders($site, $origin))
            ->postJson(self::REQUEST_ENDPOINT, ['anon_token' => self::ANON, 'email' => self::EMAIL])
            ->assertOk();

        Mail::assertSent(ClubVerificationCodeMail::class, function (ClubVerificationCodeMail $mail) use (&$captured) {
            $captured = $mail->code;

            return true;
        });

        return (string) $captured;
    }
}
