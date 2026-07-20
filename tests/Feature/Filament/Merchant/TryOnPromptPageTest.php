<?php

namespace Tests\Feature\Filament\Merchant;

use App\Filament\Merchant\Pages\TryOnPrompt;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merchant "Try-on prompt" page — the per-shop editor that writes a scope=site
 * try_on_generation Prompt the AiOperationResolver then prefers. Proves: it renders, a save
 * creates an ACTIVE site-scoped prompt stamped with the shop's own account+site, and an
 * empty save deactivates the override (fall back to the platform default).
 */
class TryOnPromptPageTest extends TestCase
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
        Filament::setTenant($this->site);
    }

    public function test_page_renders_for_the_owner(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(TryOnPrompt::class)->assertOk();
        });
    }

    public function test_saving_writes_an_active_site_scoped_prompt(): void
    {
        Tenant::run($this->account->id, function (): void {
            Livewire::test(TryOnPrompt::class)
                ->assertOk()
                ->fillForm(['user_prompt' => 'Render it faithfully. It is made of {{materials}}.'])
                ->call('save')
                ->assertHasNoFormErrors();
        });

        $prompt = Tenant::run($this->account->id, fn (): ?Prompt => Prompt::query()
            ->siteScoped($this->account->id, $this->site->id, AiOperation::KEY_TRY_ON_GENERATION)
            ->first());

        $this->assertNotNull($prompt);
        $this->assertSame(Prompt::SCOPE_SITE, $prompt->scope);
        $this->assertSame($this->account->id, (int) $prompt->account_id);
        $this->assertSame($this->site->id, (int) $prompt->site_id);
        $this->assertSame('Render it faithfully. It is made of {{materials}}.', $prompt->user_prompt);
        $this->assertTrue((bool) $prompt->is_active);
    }

    public function test_saving_empty_deactivates_the_site_override(): void
    {
        // First set an override.
        Tenant::run($this->account->id, function (): void {
            Livewire::test(TryOnPrompt::class)
                ->fillForm(['user_prompt' => 'A custom prompt.'])
                ->call('save');
        });

        // Then clear it -> the site override deactivates (resolver falls back to the default).
        Tenant::run($this->account->id, function (): void {
            Livewire::test(TryOnPrompt::class)
                ->fillForm(['user_prompt' => ''])
                ->call('save')
                ->assertHasNoFormErrors();
        });

        $active = Tenant::run($this->account->id, fn (): ?Prompt => Prompt::query()
            ->siteScoped($this->account->id, $this->site->id, AiOperation::KEY_TRY_ON_GENERATION)
            ->first());

        $this->assertNull($active); // siteScoped only returns is_active=true rows
    }
}
