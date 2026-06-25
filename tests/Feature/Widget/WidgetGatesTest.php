<?php

namespace Tests\Feature\Widget;

use App\Models\CreditLedger;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two independent gates at the widget boundary. A gate denial is a TYPED result with
 * the right status, NEVER a 500, NEVER a charge, NEVER an OpenRouter call (no generation
 * row is even created). After POST /leads the lead gate re-opens. The gates never collapse.
 */
final class WidgetGatesTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    private const ANON = 'anon_gate_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_out_of_credits_returns_typed_denial_without_charge_or_openrouter(): void
    {
        Http::fake([self::OR_BASE.'/chat/completions' => Http::response([], 500)]);
        $ctx = $this->makeSiteContext();
        $this->drainCredits($ctx['account']);

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->generationBody($ctx));

        // Typed out-of-credits (402), a graceful block — not a 500.
        $response->assertStatus(402)
            ->assertJson(['ok' => false, 'blocked' => true, 'reason' => 'insufficient_credits']);

        // NO OpenRouter call, NO generation row, NO charge.
        Http::assertNothingSent();
        $this->assertSame(0, Tenant::run($ctx['account'], fn () => Generation::query()->count()));
        $this->assertSame(0, Tenant::run($ctx['account'], fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->count()));
    }

    public function test_free_tries_exhausted_and_unregistered_returns_signup_required_without_openrouter(): void
    {
        Http::fake([self::OR_BASE.'/chat/completions' => Http::response([], 500)]);
        // 0 free tries -> signup required before the first try.
        $ctx = $this->makeSiteContext(['free_generations_before_signup' => 0]);

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->generationBody($ctx));

        // Signup-required is still actionable -> 200 + blocked:true so the widget shows the form.
        $response->assertStatus(200)
            ->assertJson(['ok' => false, 'blocked' => true, 'reason' => 'signup_required']);

        Http::assertNothingSent();
        $this->assertSame(0, Tenant::run($ctx['account'], fn () => Generation::query()->count()));
    }

    public function test_lead_gate_reopens_after_signup(): void
    {
        $this->fakeOpenRouterSuccess();
        $ctx = $this->makeSiteContext([
            'free_generations_before_signup' => 0,
            'post_signup_grant' => ['type' => 'unlimited'],
        ]);

        // Before signup: blocked.
        $blocked = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->generationBody($ctx));
        $blocked->assertStatus(200)->assertJson(['reason' => 'signup_required']);

        // Sign up.
        $signup = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/leads', [
                'full_name' => 'Dana Levi',
                'email' => 'dana@example.com',
                'anon_token' => self::ANON,
            ]);
        $signup->assertStatus(201)
            ->assertJson(['ok' => true, 'lead' => ['registered' => true], 'allowance' => ['allowed' => true]]);

        // After signup the lead gate re-opens: the generation now starts.
        $ok = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->generationBody($ctx));
        $ok->assertStatus(201)->assertJson(['ok' => true]);
    }

    public function test_marketing_consent_defaults_off_unless_explicitly_true(): void
    {
        $ctx = $this->makeSiteContext();

        // Signup WITHOUT a marketing_consent field -> stored OFF.
        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/leads', [
                'full_name' => 'No Optin', 'email' => 'nooptin@example.com', 'anon_token' => self::ANON,
            ])->assertStatus(201)->assertJson(['lead' => ['marketing_consent' => false]]);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $this->assertFalse($endUser->hasMarketingConsent());
        $this->assertNull($endUser->marketing_consent_at);

        // A second lead WITH explicit true -> ON.
        $ctx2 = $this->makeSiteContext([], 'https://opt.example.com');
        $this->withHeaders($this->widgetHeaders($ctx2['site'], $ctx2['origin']))
            ->postJson('/widget/v1/leads', [
                'full_name' => 'Yes Optin', 'email' => 'yes@example.com',
                'anon_token' => 'anon_optin_1234567890', 'marketing_consent' => true,
            ])->assertStatus(201)->assertJson(['lead' => ['marketing_consent' => true]]);

        $optedIn = $this->endUserFor($ctx2['account'], $ctx2['site'], 'anon_optin_1234567890');
        $this->assertTrue($optedIn->hasMarketingConsent());
        $this->assertNotNull($optedIn->marketing_consent_at);
    }

    public function test_missing_consent_is_a_422_validation_error(): void
    {
        $ctx = $this->makeSiteContext();
        $body = $this->generationBody($ctx);
        unset($body['consent']); // consent omitted

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);

        // And explicitly false consent is also rejected.
        $body['consent'] = false;
        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);

        $this->assertSame(0, Tenant::run($ctx['account'], fn () => Generation::query()->count()));
    }

    /** Both gates would block: the credit wall (the harder stop) is surfaced, not signup. */
    public function test_when_both_gates_block_the_credit_wall_is_shown(): void
    {
        Http::fake([self::OR_BASE.'/chat/completions' => Http::response([], 500)]);
        $ctx = $this->makeSiteContext(['free_generations_before_signup' => 0]); // lead gate blocks
        $this->drainCredits($ctx['account']);                                   // credit gate also blocks

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->generationBody($ctx));

        $response->assertStatus(402)->assertJson(['reason' => 'insufficient_credits']);
        Http::assertNothingSent();
    }

    private function generationBody(array $ctx, string $crq = 'crq-gate-1'): array
    {
        return [
            'photo' => $this->photoDataUrl(),
            'height' => 175,
            'product_id' => $ctx['product']->id,
            'variant_id' => $ctx['variant']->id,
            'client_request_id' => $crq,
            'consent' => true,
            'anon_token' => self::ANON,
        ];
    }
}
