<?php

namespace Tests\Feature\Leads;

use App\Domain\Leads\LeadCapture;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GDPR launch-blocker: marketing consent DEFAULTS OFF and is a SEPARATE field from the
 * use-my-photo consent. It is never pre-checked, never implied by signup or by photo
 * consent. Only an EXPLICIT opt-in flips it (and stamps marketing_consent_at).
 */
class MarketingConsentDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_end_user_has_marketing_consent_off_by_default(): void
    {
        [$account, $site] = $this->seedAccountSite();

        $endUser = Tenant::run($account, fn () => EndUser::factory()->forSite($site)->create());

        // The stored field defaults false (the column default + the model attribute).
        $this->assertFalse($endUser->fresh()->marketing_consent);
        $this->assertFalse($endUser->hasMarketingConsent());
        $this->assertNull($endUser->fresh()->marketing_consent_at);
    }

    public function test_signup_without_an_explicit_optin_keeps_marketing_off(): void
    {
        [$account, $site] = $this->seedAccountSite();
        $endUser = Tenant::run($account, fn () => EndUser::factory()->forSite($site)->create());

        // Capture the lead with NO marketing_consent field at all.
        Tenant::run($account, fn () => app(LeadCapture::class)->register($endUser, [
            'full_name' => 'Dana Levi',
            'email' => 'dana@example.com',
            'phone' => '+972500000000',
        ]));

        $this->assertTrue($endUser->fresh()->isRegistered());
        $this->assertFalse($endUser->fresh()->marketing_consent, 'signup must not imply marketing consent');
    }

    public function test_explicit_optin_sets_marketing_consent_and_timestamp(): void
    {
        [$account, $site] = $this->seedAccountSite();
        $endUser = Tenant::run($account, fn () => EndUser::factory()->forSite($site)->create());

        Tenant::run($account, fn () => app(LeadCapture::class)->register($endUser, [
            'full_name' => 'Dana Levi',
            'email' => 'dana@example.com',
            'marketing_consent' => true,
        ]));

        $fresh = $endUser->fresh();
        $this->assertTrue($fresh->marketing_consent);
        $this->assertNotNull($fresh->marketing_consent_at);
    }

    public function test_photo_consent_is_independent_of_marketing_consent(): void
    {
        [$account, $site] = $this->seedAccountSite();

        // A shopper who gave photo consent but did NOT opt in to marketing.
        $endUser = Tenant::run($account, fn () => EndUser::factory()->forSite($site)->create([
            'photo_consent_at' => now(),
        ]));

        $this->assertTrue($endUser->hasPhotoConsent());
        $this->assertFalse($endUser->hasMarketingConsent(), 'photo consent must never imply marketing consent');
    }

    private function seedAccountSite(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        return [$account, $site];
    }
}
