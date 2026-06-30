<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Scan\ScanProductJob;
use App\Filament\Platform\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Sites "Scan a product" action — a super-admin scans a product page for any
 * site from the platform panel; the job is dispatched with the site's explicit
 * account_id (per the tenancy contract).
 */
class PlatformSiteScanActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_super_admin_scans_a_product_for_a_site(): void
    {
        Bus::fake([ScanProductJob::class]);

        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Livewire::test(ListSites::class)
            ->callTableAction('scan', $site, data: ['url' => 'https://shop.test/products/ring'])
            ->assertHasNoTableActionErrors();

        Bus::assertDispatched(
            ScanProductJob::class,
            fn (ScanProductJob $job): bool => $job->siteId === $site->id
                && $job->url === 'https://shop.test/products/ring',
        );
    }
}
