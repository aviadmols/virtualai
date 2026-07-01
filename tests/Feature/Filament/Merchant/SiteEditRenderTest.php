<?php

namespace Tests\Feature\Filament\Merchant;

use App\Filament\Merchant\Resources\SiteResource\Pages\EditSite;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merchant EditSite page — renders for the owner, 404s on a foreign-account site.
 *
 * Proves the new merchant edit page wires up (renders + saves the owner's own site) and
 * stays tenant-safe: a hand-crafted URL carrying another account's site id resolves
 * through the account-scoped resource query to ModelNotFoundException, never B's row.
 */
class SiteEditRenderTest extends TestCase
{
    use RefreshDatabase;

    private Account $accountA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountA = Account::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->accountA)->create());
    }

    public function test_own_site_edit_page_renders_and_saves(): void
    {
        Tenant::run($this->accountA->id, function (): void {
            // A full-URL domain (what the create form's ->url() rule stores) so re-saving
            // the loaded record validates as it would for a real merchant-created site.
            $site = Site::factory()->forAccount($this->accountA)->create([
                'name' => 'Old name',
                'domain' => 'https://shop-a.test',
            ]);

            // The panel is shop-centric (Filament tenant = Site); set the active shop so the
            // per-tenant resource URLs resolve.
            Filament::setTenant($site);

            Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
                ->assertOk()
                ->fillForm(['name' => 'New name'])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame('New name', $site->fresh()->name);
        });
    }

    public function test_edit_page_404s_on_a_foreign_account_site(): void
    {
        $accountB = Account::factory()->create();
        $foreign = Site::factory()->forAccount($accountB)->create();
        $ownSite = Site::factory()->forAccount($this->accountA)->create();

        $this->expectException(ModelNotFoundException::class);

        Tenant::run($this->accountA->id, function () use ($ownSite, $foreign) {
            // Active shop is the merchant's OWN; a hand-crafted URL for another account's site
            // resolves through the account-scoped record binding to ModelNotFoundException.
            Filament::setTenant($ownSite);

            Livewire::test(EditSite::class, ['record' => $foreign->getRouteKey()]);
        });
    }
}
