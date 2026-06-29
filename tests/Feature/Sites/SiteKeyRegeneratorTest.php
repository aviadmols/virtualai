<?php

namespace Tests\Feature\Sites;

use App\Domain\Sites\SiteKeyRegenerator;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP-M2 — rotating the PUBLIC site_key invalidates the old key (only one is stored) and
 * NEVER exposes or mutates the server-only widget_secret.
 */
class SiteKeyRegeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function regenerator(): SiteKeyRegenerator
    {
        return app(SiteKeyRegenerator::class);
    }

    public function test_regenerate_mints_a_new_key_and_invalidates_the_old_one(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $oldKey = $site->site_key;

        $newKey = Tenant::run($account, fn () => $this->regenerator()->regenerate($site));

        $this->assertNotSame($oldKey, $newKey);
        $this->assertStringStartsWith('site_', $newKey);

        // The stored key is the new one — the old key resolves to no site (invalidated).
        $this->assertSame($newKey, $site->fresh()->site_key);
        $resolvedByOld = Tenant::run($account, fn () => Site::query()->where('site_key', $oldKey)->first());
        $this->assertNull($resolvedByOld);
    }

    public function test_regenerate_leaves_widget_secret_untouched_and_never_returns_it(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $secretBefore = $site->widget_secret; // decrypted via the cast

        $newKey = Tenant::run($account, fn () => $this->regenerator()->regenerate($site));

        // The secret is unchanged by the rotation.
        $this->assertSame($secretBefore, $site->fresh()->widget_secret);

        // The returned value is the public key, NOT the secret.
        $this->assertNotSame($secretBefore, $newKey);

        // widget_secret never leaks via serialization (it is $hidden on the model).
        $this->assertArrayNotHasKey('widget_secret', $site->fresh()->toArray());
    }

    public function test_regenerate_records_a_trace_without_logging_the_key_value(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $newKey = Tenant::run($account, fn () => $this->regenerator()->regenerate($site));

        $event = Tenant::run($account, fn () => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_SITE_KEY_REGENERATED)
            ->where('subject_id', $site->id)
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($site->id, (int) $event->site_id);
        // The trace must not carry the key value (avoid leaking it into the timeline).
        $this->assertStringNotContainsString($newKey, json_encode($event->details));
    }
}
