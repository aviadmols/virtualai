<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\GateDenied;
use App\Domain\Credits\UsageGate;
use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * UsageGate — per-(account,site) + per-account generation caps return a TYPED GateDenied
 * (a typed 429, never a 500), and the optional plan limits/features return typed denials.
 * The gate composes with CreditGate + LeadGate; it never collapses them.
 */
class UsageGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('widget_generate_site:1:1');
        config()->set('trayon.usage.site_widget_rpm', 3);
        config()->set('trayon.usage.account_gen_rpm', 100);
    }

    public function test_under_the_cap_passes(): void
    {
        [$account, $site] = $this->seedAccountSite();

        $decision = UsageGate::for($account)->checkWidgetGenerate($site);

        $this->assertTrue($decision->allowed);
        $this->assertFalse($decision->denied());
    }

    public function test_per_site_cap_denies_with_typed_rate_limited(): void
    {
        [$account, $site] = $this->seedAccountSite();
        $gate = UsageGate::for($account);

        // Burn the 3/min site cap.
        $gate->checkWidgetGenerate($site);
        $gate->checkWidgetGenerate($site);
        $gate->checkWidgetGenerate($site);

        $denied = $gate->checkWidgetGenerate($site);

        // A TYPED denial, never an exception/500.
        $this->assertFalse($denied->allowed);
        $this->assertTrue($denied->isRateLimited());
        $this->assertSame(GateDenied::REASON_RATE_LIMITED, $denied->reason);
        $this->assertGreaterThan(0, $denied->retryAfterSeconds);
    }

    public function test_per_account_ceiling_denies_across_sites(): void
    {
        config()->set('trayon.usage.site_widget_rpm', 100); // high site cap
        config()->set('trayon.usage.account_gen_rpm', 2);   // low account ceiling

        $account = Account::factory()->create();
        $siteA = Site::factory()->forAccount($account)->create();
        $siteB = Site::factory()->forAccount($account)->create();
        $gate = UsageGate::for($account);

        // Two generations across two different sites burn the account ceiling.
        $gate->checkWidgetGenerate($siteA);
        $gate->checkWidgetGenerate($siteB);

        $denied = $gate->checkWidgetGenerate($siteA);
        $this->assertTrue($denied->isRateLimited());
    }

    public function test_site_override_raises_the_cap(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create(['usage_limits' => ['widget_rpm' => 5]]);
        $gate = UsageGate::for($account);

        // Config default is 3, but this site overrides to 5: the 4th still passes.
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($gate->checkWidgetGenerate($site)->allowed, "attempt {$i} should pass");
        }
    }

    public function test_plan_limit_and_feature_return_typed_denials(): void
    {
        $account = Account::factory()->create();
        $gate = UsageGate::for($account);

        // Countable limit: at the ceiling -> planLimit.
        $this->assertTrue($gate->assertWithin('max_sites', 2, 5)->allowed);
        $this->assertSame(GateDenied::REASON_PLAN_LIMIT, $gate->assertWithin('max_sites', 5, 5)->reason);
        $this->assertTrue($gate->assertWithin('max_sites', 99, null)->allowed); // null = unlimited

        // Boolean feature.
        $this->assertTrue($gate->assertFeature('custom_branding', true)->allowed);
        $this->assertSame(GateDenied::REASON_PLAN_FEATURE, $gate->assertFeature('custom_branding', false)->reason);
    }

    private function seedAccountSite(): array
    {
        $account = Account::factory()->create(['id' => 1]);
        $site = Site::factory()->forAccount($account)->create(['id' => 1]);

        return [$account, $site];
    }
}
