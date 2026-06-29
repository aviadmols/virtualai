<?php

namespace Tests\Feature\Sites;

use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP-M4 — the validated privacy/retention settings writer. Valid values persist; an
 * out-of-range value is rejected with a typed exception and NOTHING is written (no partial
 * save); only the four whitelisted columns are ever touched.
 */
class SiteSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SiteSettingsService
    {
        return app(SiteSettingsService::class);
    }

    private function site(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create([
            'retention_days' => 30,
            'free_generations_before_signup' => 2,
        ]);

        return [$account, $site];
    }

    public function test_valid_settings_persist(): void
    {
        [$account, $site] = $this->site();

        $updated = Tenant::run($account, fn () => $this->service()->update($site, [
            'retention_days' => 90,
            'free_generations_before_signup' => 0,
            'privacy_config' => ['consent_copy' => 'We use your photo to render the try-on.'],
            'gallery_settings' => ['autoplay' => true],
        ]));

        $fresh = $updated->fresh();
        $this->assertSame(90, $fresh->retention_days);
        $this->assertSame(0, $fresh->free_generations_before_signup);
        $this->assertSame('We use your photo to render the try-on.', $fresh->privacy_config['consent_copy']);
        $this->assertTrue($fresh->gallery_settings['autoplay']);

        $event = Tenant::run($account, fn () => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_SITE_SETTINGS_UPDATED)
            ->where('subject_id', $site->id)
            ->first());
        $this->assertNotNull($event);
    }

    public function test_null_retention_is_the_until_delete_sentinel(): void
    {
        [$account, $site] = $this->site();

        $updated = Tenant::run($account, fn () => $this->service()->update($site, [
            'retention_days' => Site::RETENTION_UNTIL_DELETE, // null
        ]));

        $this->assertNull($updated->fresh()->retention_days);
    }

    public function test_null_free_generations_means_signup_never_required(): void
    {
        [$account, $site] = $this->site();

        $updated = Tenant::run($account, fn () => $this->service()->update($site, [
            'free_generations_before_signup' => null,
        ]));

        $this->assertNull($updated->fresh()->free_generations_before_signup);
    }

    public function test_partial_patch_leaves_absent_keys_untouched(): void
    {
        [$account, $site] = $this->site();

        Tenant::run($account, fn () => $this->service()->update($site, ['retention_days' => 7]));

        $fresh = $site->fresh();
        $this->assertSame(7, $fresh->retention_days);
        // free_generations_before_signup was absent from the patch — unchanged.
        $this->assertSame(2, $fresh->free_generations_before_signup);
    }

    public function test_out_of_range_retention_days_is_rejected_and_persists_nothing(): void
    {
        [$account, $site] = $this->site();

        try {
            Tenant::run($account, fn () => $this->service()->update($site, [
                'retention_days' => 14, // not 7/30/90/null
                'free_generations_before_signup' => 0,
            ]));
            $this->fail('Expected InvalidSiteSettingsException for retention_days=14.');
        } catch (InvalidSiteSettingsException $e) {
            $this->assertSame(InvalidSiteSettingsException::REASON_RETENTION_DAYS, $e->reason);
        }

        // Validate-then-persist: NOTHING was written (the sibling valid key did not save).
        $fresh = $site->fresh();
        $this->assertSame(30, $fresh->retention_days);
        $this->assertSame(2, $fresh->free_generations_before_signup);
    }

    public function test_negative_free_generations_is_rejected(): void
    {
        [$account, $site] = $this->site();

        $this->expectException(InvalidSiteSettingsException::class);
        Tenant::run($account, fn () => $this->service()->update($site, [
            'free_generations_before_signup' => -1,
        ]));
    }

    public function test_a_non_object_privacy_config_is_rejected(): void
    {
        [$account, $site] = $this->site();

        try {
            Tenant::run($account, fn () => $this->service()->update($site, [
                'privacy_config' => ['just', 'a', 'list'], // a list, not an object
            ]));
            $this->fail('Expected InvalidSiteSettingsException for a list privacy_config.');
        } catch (InvalidSiteSettingsException $e) {
            $this->assertSame(InvalidSiteSettingsException::REASON_NOT_AN_OBJECT, $e->reason);
            $this->assertSame('privacy_config', $e->field);
        }
    }

    public function test_a_settings_save_never_touches_site_key_or_widget_secret(): void
    {
        [$account, $site] = $this->site();
        $keyBefore = $site->site_key;
        $secretBefore = $site->widget_secret;

        // A malicious/extra key in the patch is ignored — only whitelisted columns write.
        Tenant::run($account, fn () => $this->service()->update($site, [
            'retention_days' => 90,
            'site_key' => 'site_attacker_supplied',
            'widget_secret' => 'leaked',
        ]));

        $fresh = $site->fresh();
        $this->assertSame($keyBefore, $fresh->site_key);
        $this->assertSame($secretBefore, $fresh->widget_secret);
        $this->assertSame(90, $fresh->retention_days);
    }
}
