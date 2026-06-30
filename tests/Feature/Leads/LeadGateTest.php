<?php

namespace Tests\Feature\Leads;

use App\Domain\Leads\LeadDecision;
use App\Domain\Leads\LeadGate;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LeadGate — the END-USER free-tries gate. Independent of the merchant CreditGate.
 * Reads the site's free_generations_before_signup against the lead's
 * generations_used; a block is a TYPED "signup required" result, never a 500.
 *
 *  - N free tries -> the (N+1)th requires signup.
 *  - 0            -> signup before the first try.
 *  - null         -> signup never required.
 *  - post_signup_grant re-opens it after registration.
 */
class LeadGateTest extends TestCase
{
    use RefreshDatabase;

    private function site(?int $freeBefore, array $postSignupGrant = []): Site
    {
        $account = Account::factory()->create();

        return Site::factory()->forAccount($account)->create([
            'free_generations_before_signup' => $freeBefore,
            'post_signup_grant' => $postSignupGrant,
        ]);
    }

    public function test_allows_while_under_the_free_limit(): void
    {
        $site = $this->site(2);
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(1)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertInstanceOf(LeadDecision::class, $decision);
        $this->assertTrue($decision->allowed);
        $this->assertSame(1, $decision->freeRemaining);
    }

    public function test_blocks_with_signup_required_when_free_tries_exhausted(): void
    {
        $site = $this->site(2);
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(2)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        // Typed result, not an exception.
        $this->assertTrue($decision->denied());
        $this->assertTrue($decision->signupRequired);
        $this->assertSame(LeadDecision::REASON_SIGNUP_REQUIRED, $decision->reason);
    }

    public function test_zero_free_requires_signup_before_first_try(): void
    {
        $site = $this->site(0);
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(0)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($decision->signupRequired);
    }

    public function test_null_free_never_requires_signup(): void
    {
        $site = $this->site(null);
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(99)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($decision->allowed);
    }

    public function test_post_signup_unlimited_grant_reopens_after_registration(): void
    {
        $site = $this->site(2, ['type' => LeadGate::GRANT_TYPE_UNLIMITED]);
        $endUser = EndUser::factory()->forSite($site)->registered()->withGenerationsUsed(50)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($decision->allowed);
    }

    public function test_post_signup_limited_grant_allows_up_to_the_ceiling(): void
    {
        // free 2 + grant 3 = 5 total allowed.
        $site = $this->site(2, ['type' => LeadGate::GRANT_TYPE_LIMITED, 'amount' => 3]);

        $within = EndUser::factory()->forSite($site)->registered()->withGenerationsUsed(4)->create();
        $this->assertTrue(LeadGate::for($site, $within)->assertCanTry()->allowed);

        $atCeiling = EndUser::factory()->forSite($site)->registered()->withGenerationsUsed(5)->create();
        $blocked = LeadGate::for($site, $atCeiling)->assertCanTry();
        $this->assertTrue($blocked->denied());
        $this->assertSame(LeadDecision::REASON_POST_SIGNUP_LIMIT, $blocked->reason);
    }

    public function test_registered_with_EXPLICIT_none_grant_falls_back_to_free_count(): void
    {
        // A merchant who deliberately sets {type:'none'} keeps the free count as the cap.
        $site = $this->site(2, ['type' => LeadGate::GRANT_TYPE_NONE]);
        $endUser = EndUser::factory()->forSite($site)->registered()->withGenerationsUsed(2)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($decision->denied());
        $this->assertSame(LeadDecision::REASON_POST_SIGNUP_LIMIT, $decision->reason);
    }

    public function test_registered_with_ABSENT_grant_defaults_to_unlimited(): void
    {
        // The COMMON case: no post_signup_grant configured. Signing up must UNLOCK
        // continued try-ons (else the widget loops the signup form forever).
        $site = $this->site(2); // post_signup_grant = [] (absent)
        $endUser = EndUser::factory()->forSite($site)->registered()->withGenerationsUsed(50)->create();

        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($decision->allowed);
        $this->assertFalse($decision->signupRequired);
    }
}
