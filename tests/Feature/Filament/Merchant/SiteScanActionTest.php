<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Scan\ScanProductJob;
use App\Filament\Merchant\Resources\SiteResource\Pages\ViewSite;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Site hub "Scan a product" action — the merchant entry point that turns a pasted
 * product-page URL into a queued ScanProductJob (which lands a DRAFT product to review).
 *
 * Proves the action dispatches the job for the site's OWN account (explicit account_id,
 * per the tenancy contract) with the pasted URL.
 */
class SiteScanActionTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();
        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->account)->create());
        Filament::setTenant($this->site); // shop-centric panel: bind the active shop
    }

    public function test_scan_action_dispatches_scan_product_job_for_the_site(): void
    {
        Bus::fake([ScanProductJob::class]);

        Tenant::run($this->account->id, function (): void {
            Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])
                ->callAction('scan', ['url' => 'https://shop.test/products/blue-shirt'])
                ->assertHasNoActionErrors();
        });

        Bus::assertDispatched(
            ScanProductJob::class,
            fn (ScanProductJob $job): bool => $job->siteId === $this->site->id
                && $job->url === 'https://shop.test/products/blue-shirt',
        );
    }

    public function test_scan_action_requires_a_valid_url(): void
    {
        Bus::fake([ScanProductJob::class]);

        Tenant::run($this->account->id, function (): void {
            Livewire::test(ViewSite::class, ['record' => $this->site->getRouteKey()])
                ->callAction('scan', ['url' => 'not-a-url'])
                ->assertHasActionErrors(['url']);
        });

        Bus::assertNotDispatched(ScanProductJob::class);
    }
}
