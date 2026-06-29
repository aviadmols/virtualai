<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\PlatformSiteWriter;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Platform-admin cross-account Site WRITE seam (the write-side mirror of PlatformSiteQuery).
 *
 * RELEASE-BLOCKER tenant-safety proof: a super-admin can create/edit a site for ANY
 * account, the row lands on EXACTLY that account (no leak), the write binds the tenant
 * (so the BelongsToAccount creating-guard + global scope hold) rather than bypassing the
 * scope, and any non-super-admin caller fails LOUD before a write happens.
 */
class PlatformSiteWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creates_a_site_owned_by_the_chosen_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->create());

        $site = app(PlatformSiteWriter::class)->create($accountB->id, [
            'name' => 'B Storefront',
            'domain' => 'https://b.example.com',
            'allowed_origins' => ['https://b.example.com'],
        ]);

        // Stamped to the chosen account, with the model-generated credentials.
        $this->assertSame($accountB->id, (int) $site->account_id);
        $this->assertSame('B Storefront', $site->name);
        $this->assertNotNull($site->site_key);
        $this->assertNotNull($site->widget_secret);

        // Isolation: the new site is visible under B's tenant, never under A's.
        $underB = Tenant::run($accountB, fn (): bool => Site::query()->whereKey($site->id)->exists());
        $underA = Tenant::run($accountA, fn (): bool => Site::query()->whereKey($site->id)->exists());
        $this->assertTrue($underB);
        $this->assertFalse($underA);
    }

    public function test_super_admin_updates_a_site_within_its_own_account(): void
    {
        $accountB = Account::factory()->create();
        $site = Site::factory()->forAccount($accountB)->create(['name' => 'Old name']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $updated = app(PlatformSiteWriter::class)->update($site, ['name' => 'New name']);

        $this->assertSame($accountB->id, (int) $updated->account_id);

        $persisted = Tenant::run($accountB, fn () => Site::query()->whereKey($site->id)->value('name'));
        $this->assertSame('New name', $persisted);
    }

    public function test_a_merchant_cannot_use_the_writer_it_fails_loud(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        $this->expectException(PlatformAccessRequiredException::class);
        app(PlatformSiteWriter::class)->create($account->id, ['name' => 'Nope']);
    }

    public function test_an_unauthenticated_caller_cannot_use_the_writer(): void
    {
        Auth::logout();
        $account = Account::factory()->create();

        $this->expectException(PlatformAccessRequiredException::class);
        app(PlatformSiteWriter::class)->create($account->id, ['name' => 'Nope']);
    }

    public function test_a_blocked_write_persists_nothing(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        try {
            app(PlatformSiteWriter::class)->create($account->id, ['name' => 'Nope']);
        } catch (PlatformAccessRequiredException) {
            // expected
        }

        $count = Tenant::run($account, fn (): int => Site::query()->count());
        $this->assertSame(0, $count);
    }
}
