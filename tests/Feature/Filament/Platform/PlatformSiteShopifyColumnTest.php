<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The platform Sites list must say, for EVERY account's storefront, whether it is a
 * connected Shopify store — and whether that connection still works.
 *
 * THE TRAP THIS PINS: ShopifyConnection is itself BelongsToAccount, and the platform panel
 * binds NO tenant. Read the connection as a RELATION here and its fail-closed global scope
 * resolves it to null on every row — so the column reports "Not connected" for stores that
 * ARE connected. A silent lie, strictly worse than an error, because nobody goes looking
 * for it. (Verified: even an eager load with the scope stripped is not enough — Filament
 * re-hydrates records without it, and the lazy read re-applies the scope.)
 *
 * So the state is SELECTED as columns by a correlated subquery inside the audited
 * PlatformSiteQuery seam. These tests assert on the rows the table ACTUALLY renders — not
 * on a factory-made model that never went through the query — which is the only way the
 * assertion can fail if that seam regresses.
 */
class PlatformSiteShopifyColumnTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHOP_DOMAIN = 'connected-shop.myshopify.com';

    private const COL_STATUS = 'shopify_status';

    private const COL_REAUTH = 'shopify_needs_reauth';

    private const COL_DOMAIN = 'shopify_shop_domain';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /** A site with a real connection, written under its own tenant exactly as production does. */
    private function connectedSite(array $attributes = [], string $domain = self::SHOP_DOMAIN): Site
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run((int) $account->getKey(), static function () use ($account, $site, $attributes, $domain): void {
            ShopifyConnection::factory()->create([
                'account_id' => $account->getKey(),
                'site_id' => $site->getKey(),
                'shop_domain' => $domain,
            ] + $attributes);
        });

        return $site;
    }

    /** The row as the TABLE built it — the only record whose state the column ever sees. */
    private function renderedRow(Site $site): Site
    {
        $row = Livewire::test(ListSites::class)
            ->instance()
            ->getTableRecords()
            ->firstWhere((new Site)->getKeyName(), $site->getKey());

        $this->assertNotNull($row, 'The platform list must show every account\'s site.');

        return $row;
    }

    public function test_a_connected_store_is_shown_as_connected_and_never_as_not_connected(): void
    {
        $row = $this->renderedRow($this->connectedSite());

        // Strip the subquery from PlatformSiteQuery (or read the relation instead) and this
        // is null -> the badge silently reads "Not connected" for a store that IS connected.
        $this->assertSame(
            ShopifyConnection::STATUS_INSTALLED,
            $row->getAttribute(self::COL_STATUS),
            'A connected store must not read as unconnected in the platform list.'
        );
        $this->assertSame(self::SHOP_DOMAIN, $row->getAttribute(self::COL_DOMAIN));
    }

    public function test_the_connected_store_domain_is_rendered_to_the_super_admin(): void
    {
        $this->connectedSite();

        Livewire::test(ListSites::class)->assertSee(self::SHOP_DOMAIN);
    }

    public function test_a_revoked_token_is_flagged_and_does_not_pass_as_healthy(): void
    {
        // A connection can be `installed` and still hold a token Shopify has revoked. It
        // LOOKS healthy and syncs nothing — needs_reauth must outrank the status.
        $row = $this->renderedRow($this->connectedSite([
            'status' => ShopifyConnection::STATUS_INSTALLED,
            'needs_reauth' => true,
        ]));

        $this->assertSame(ShopifyConnection::STATUS_INSTALLED, $row->getAttribute(self::COL_STATUS));
        $this->assertTrue((bool) $row->getAttribute(self::COL_REAUTH), 'A revoked token must be visible.');
    }

    public function test_an_uninstalled_store_is_shown_as_disconnected(): void
    {
        $row = $this->renderedRow($this->connectedSite([
            'status' => ShopifyConnection::STATUS_UNINSTALLED,
            'uninstalled_at' => now(),
        ]));

        $this->assertSame(ShopifyConnection::STATUS_UNINSTALLED, $row->getAttribute(self::COL_STATUS));
    }

    public function test_a_custom_storefront_with_no_connection_reads_as_not_connected(): void
    {
        $site = Site::factory()->forAccount(Account::factory()->create())->create();

        $this->assertNull($this->renderedRow($site)->getAttribute(self::COL_STATUS));
    }

    public function test_each_account_s_own_connection_is_reported_across_accounts(): void
    {
        // The whole point of the platform list: many accounts, one table, no cross-talk and
        // no row borrowing another row's connection.
        $connected = $this->connectedSite(domain: 'shop-a.myshopify.com');
        $custom = Site::factory()->forAccount(Account::factory()->create())->create();

        $this->assertSame('shop-a.myshopify.com', $this->renderedRow($connected)->getAttribute(self::COL_DOMAIN));
        $this->assertNull($this->renderedRow($custom)->getAttribute(self::COL_DOMAIN));
    }
}
