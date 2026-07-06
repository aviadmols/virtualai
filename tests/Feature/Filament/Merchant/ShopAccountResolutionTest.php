<?php

namespace Tests\Feature\Filament\Merchant;

use App\Filament\Merchant\Widgets\BalanceWidget;
use App\Filament\Merchant\Widgets\CreditBannerWidget;
use App\Filament\Merchant\Widgets\MerchantKpiWidget;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merchant credit / KPI widgets resolve the CURRENT SHOP TENANT's account
 * (Filament::getTenant()->account), NOT Auth::user()->account. A super-admin drilled
 * into a shop carries no own account, so the old auth-user path returned null into a
 * `: Account` return type → TypeError → 500 on /merchant/{shop}/credit-ledgers and the
 * dashboard. These pin that the widgets render from the SHOP's account instead.
 */
class ShopAccountResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('merchant'));
    }

    public function test_credit_widgets_render_for_a_super_admin_drilled_into_a_shop(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $superAdmin = User::factory()->superAdmin()->create(); // account_id === null

        $this->actingAs($superAdmin);
        Filament::setTenant($site);

        // Old behavior: Auth::user()->account === null → build(null) → TypeError → 500.
        // Now each widget resolves the SHOP tenant's account and renders cleanly.
        Livewire::test(BalanceWidget::class)->assertOk();
        Livewire::test(MerchantKpiWidget::class)->assertOk();
        Livewire::test(CreditBannerWidget::class)->assertOk();
    }

    public function test_a_normal_owner_still_renders_from_their_own_shop_account(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $owner = User::factory()->forAccount($account)->create();

        $this->actingAs($owner);
        Filament::setTenant($site);

        Livewire::test(BalanceWidget::class)->assertOk();
        Livewire::test(MerchantKpiWidget::class)->assertOk();
    }
}
