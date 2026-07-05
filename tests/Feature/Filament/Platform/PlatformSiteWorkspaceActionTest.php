<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Platform\PlatformShopDrillIn;
use App\Filament\Merchant\Pages\Dashboard;
use App\Filament\Platform\Resources\SiteResource;
use App\Filament\Platform\Resources\SiteResource\Pages\EditSite;
use App\Filament\Platform\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Sites "Open shop workspace" bridge — a super-admin drills straight from the
 * platform panel into a shop's merchant workspace (its tools), instead of only the admin
 * Edit form. Proves: the action renders, it yields the correct merchant-panel tenant URL,
 * a super-admin may access the site-tenant, and the drill-in is audited (account-scoped).
 */
class PlatformSiteWorkspaceActionTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ACTION = 'workspace';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($this->superAdmin);
    }

    public function test_workspace_row_action_renders_for_a_super_admin(): void
    {
        $site = Site::factory()->forAccount(Account::factory()->create())->create();

        Livewire::test(ListSites::class)
            ->assertTableActionExists(self::ACTION, record: $site)
            ->assertTableActionVisible(self::ACTION, record: $site);
    }

    public function test_workspace_action_redirects_to_the_merchant_tenant_url(): void
    {
        $site = Site::factory()->forAccount(Account::factory()->create())->create();

        $expected = Dashboard::getUrl(panel: 'merchant', tenant: $site);

        Livewire::test(ListSites::class)
            ->callTableAction(self::ACTION, $site)
            ->assertHasNoTableActionErrors()
            ->assertRedirect($expected);

        // The URL targets this site's own tenant slug (the shop's workspace), not the admin CRUD.
        $this->assertStringContainsString('/merchant/'.$site->slug, $expected);
    }

    public function test_workspace_header_action_is_attached_to_the_edit_page(): void
    {
        $site = Site::factory()->forAccount(Account::factory()->create())->create();

        Livewire::test(EditSite::class, ['record' => $site->getKey()])
            ->assertActionExists(self::ACTION)
            ->callAction(self::ACTION)
            ->assertRedirect(Dashboard::getUrl(panel: 'merchant', tenant: $site));
    }

    public function test_super_admin_may_access_any_shop_tenant(): void
    {
        $site = Site::factory()->forAccount(Account::factory()->create())->create();

        $this->assertTrue($this->superAdmin->canAccessTenant($site));
    }

    public function test_drill_in_records_an_audited_event_scoped_to_the_target_account(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        app(PlatformShopDrillIn::class)->record($site);

        // The trace is account-scoped to the TARGET shop's account; read it under that tenant.
        $event = Tenant::run($account, static fn (): ?ActivityEvent => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_PLATFORM_SHOP_DRILL_IN)
            ->where('subject_type', Site::class)
            ->where('subject_id', $site->getKey())
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame($site->id, $event->site_id);
        $this->assertSame($this->superAdmin->id, $event->details['super_admin_id']);
    }

    public function test_workspace_url_requires_a_super_admin(): void
    {
        $owner = User::factory()->forAccount(Account::factory()->create())->create();
        $this->actingAs($owner);

        $site = Site::factory()->forAccount(Account::factory()->create())->create();

        $this->expectException(\App\Exceptions\PlatformAccessRequiredException::class);
        app(PlatformShopDrillIn::class)->workspaceUrl($site);
    }
}
