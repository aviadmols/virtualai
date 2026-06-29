<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\AccountResource\Pages\CreateAccount;
use App\Filament\Platform\Resources\AccountResource\Pages\EditAccount;
use App\Filament\Platform\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Platform\Resources\SiteResource\Pages\EditSite;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform control-plane CRUD — render + write smoke tests.
 *
 * Catches Filament form/page wiring errors (the kind a domain test misses but that would
 * 500 the live create/edit pages), and proves the create flow runs end-to-end through the
 * audited seams: provisioning an account + owner, and creating a site for a chosen account.
 */
class PlatformAdminCrudRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_create_account_page_renders(): void
    {
        Livewire::test(CreateAccount::class)->assertOk();
    }

    public function test_create_account_form_provisions_account_and_owner(): void
    {
        Livewire::test(CreateAccount::class)
            ->fillForm([
                'name' => 'Acme',
                'company_name' => 'Acme Inc',
                'billing_email' => 'billing@acme.test',
                'locale' => 'en',
                'owner_name' => 'Owner One',
                'owner_email' => 'owner@acme.test',
                'owner_password' => 'secret-password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('accounts', ['name' => 'Acme']);
        $this->assertDatabaseHas('users', ['email' => 'owner@acme.test', 'is_super_admin' => false]);
    }

    public function test_edit_account_page_renders(): void
    {
        $account = Account::factory()->create();

        Livewire::test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->assertOk();
    }

    public function test_create_site_page_renders(): void
    {
        Livewire::test(CreateSite::class)->assertOk();
    }

    public function test_create_site_form_creates_a_site_for_the_chosen_account(): void
    {
        $account = Account::factory()->create();

        Livewire::test(CreateSite::class)
            ->fillForm([
                'account_id' => $account->id,
                'name' => 'Acme Storefront',
                'domain' => 'https://shop.acme.test',
                'allowed_origins' => ['https://shop.acme.test'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $exists = Tenant::run($account, fn (): bool => Site::query()->where('name', 'Acme Storefront')->exists());
        $this->assertTrue($exists);
    }

    public function test_edit_site_page_renders_through_the_audited_seam(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertOk();
    }
}
