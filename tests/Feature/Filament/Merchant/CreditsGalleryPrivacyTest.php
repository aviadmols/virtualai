<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Credits\Payments\CreditPaymentProvider;
use App\Domain\Credits\Payments\CreditProviderResolver;
use App\Domain\Credits\Payments\PurchaseIntent;
use App\Domain\Media\MediaStorage;
use App\Filament\Merchant\Pages\BuyCredits;
use App\Filament\Merchant\Pages\Gallery;
use App\Filament\Merchant\Pages\PrivacySettings;
use App\Filament\Merchant\Resources\CreditLedgerResource\Pages\ListCreditLedger;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * M7 / M8 / M9 (A11–A13) render + bind + tenancy tests for the final merchant
 * screens: the credit ledger (read-only, account-scoped, opening grant only on a
 * fresh account), buy-credits (the PurchaseInitiator rail → redirect; money-safe),
 * the per-site gallery (succeeded only, purged → placeholder), and privacy/retention
 * settings (validate-then-persist via SiteSettingsService; a bad value is a field
 * error, never a 500). Tenant-safety: every screen is account-scoped by the bound
 * tenant — no manual where(account_id), no withoutGlobalScopes().
 */
class CreditsGalleryPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');

        $this->account = Account::factory()->create();
        $this->owner = User::factory()->forAccount($this->account)->create();
        $this->site = Site::factory()->forAccount($this->account)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($this->owner);
        // The merchant panel is shop-centric (Filament tenant = Site); bind the active shop.
        Filament::setTenant($this->site);
    }

    /** Run the body with the owner's account bound, as BindMerchantAccount would. */
    private function asMerchant(callable $body): mixed
    {
        return Tenant::run($this->account->id, $body);
    }

    // ---------------------------------------------------------------- M7 ledger

    public function test_fresh_account_ledger_shows_only_the_opening_grant(): void
    {
        $this->asMerchant(function (): void {
            // A new account is created with one opening grant row (the observer).
            $rows = CreditLedger::query()->get();
            $this->assertCount(1, $rows);
            $this->assertSame(CreditLedger::TYPE_GRANT, $rows->first()->type);

            // The list renders the opening-grant row (badge resolves through the ledger
            // machine) and mounts the A1 balance band as a header widget.
            Livewire::test(ListCreditLedger::class)
                ->assertOk()
                ->assertSee(__(\App\Support\Ui\StatusBadge::label('ledger', CreditLedger::TYPE_GRANT)))
                ->assertSeeLivewire(\App\Filament\Merchant\Widgets\BalanceWidget::class);
        });
    }

    public function test_balance_band_renders_spendable_balance_reserved(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(\App\Filament\Merchant\Widgets\BalanceWidget::class)
                ->assertOk()
                ->assertSee(__('credits.kpi.spendable'))
                ->assertSee(__('credits.kpi.balance'))
                ->assertSee(__('credits.kpi.reserved'));
        });
    }

    public function test_ledger_is_read_only(): void
    {
        $resource = \App\Filament\Merchant\Resources\CreditLedgerResource::class;

        $this->assertFalse($resource::canCreate());

        $this->asMerchant(function () use ($resource): void {
            $row = CreditLedger::query()->first();
            $this->assertFalse($resource::canEdit($row));
            $this->assertFalse($resource::canDelete($row));
        });
    }

    public function test_a_foreign_accounts_ledger_rows_are_not_listed(): void
    {
        $other = Account::factory()->create(); // its own opening grant, account B

        $this->asMerchant(function () use ($other): void {
            $accountIds = CreditLedger::query()->pluck('account_id')->unique();

            // Bound to account A, only A's rows are visible (global scope).
            $this->assertSame([$this->account->id], $accountIds->values()->all());
            $this->assertNotContains($other->id, $accountIds->all());
        });
    }

    // ----------------------------------------------------------- M7 buy credits

    public function test_buy_credits_renders_the_preset_picker(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(BuyCredits::class)
                ->assertOk()
                ->assertSee(__('credits.buy.heading'))
                ->assertSee('$10')
                ->assertSee('$100');
        });
    }

    /** Bind a REAL resolver wrapping a mocked provider (only the interface is mocked;
        both PurchaseInitiator + CreditProviderResolver are final). The real, final
        PurchaseInitiator then runs end-to-end and writes the pending purchase row. */
    private function fakeProvider(PurchaseIntent $intent, bool $expectCall = true): void
    {
        $provider = Mockery::mock(CreditPaymentProvider::class);
        $provider->shouldReceive('name')->andReturn('payplus');
        $expectation = $provider->shouldReceive('initiatePurchase')->andReturn($intent);
        $expectCall ? $expectation->once() : $provider->shouldNotReceive('initiatePurchase');

        $this->app->instance(
            CreditProviderResolver::class,
            new CreditProviderResolver(['payplus' => $provider]),
        );
    }

    public function test_checkout_redirects_to_the_provider_payment_page(): void
    {
        $this->asMerchant(function (): void {
            $this->fakeProvider(
                PurchaseIntent::created('payplus', 'ref-1', 'https://pay.example/redirect', 25_000_000),
            );

            Livewire::test(BuyCredits::class)
                ->call('selectAmount', 25)
                ->call('checkout')
                ->assertRedirect('https://pay.example/redirect');
        });
    }

    public function test_checkout_without_an_amount_does_not_initiate(): void
    {
        $this->asMerchant(function (): void {
            $this->fakeProvider(
                PurchaseIntent::created('payplus', 'ref-x', 'https://pay.example/never', 0),
                expectCall: false,
            );

            Livewire::test(BuyCredits::class)
                ->call('checkout')
                ->assertNoRedirect();
        });
    }

    // ------------------------------------------------------------------ M8 gallery

    public function test_gallery_renders_succeeded_try_ons_for_the_site(): void
    {
        $this->asMerchant(function (): void {
            $product = Product::factory()->forSite($this->site)->confirmed()->create(['name' => 'Red Sneaker']);
            $variant = ProductVariant::factory()->forProduct($product)->create(['options' => ['color' => 'Red']]);
            $lead = EndUser::factory()->forSite($this->site)->create();

            $gen = Generation::factory()->forContext($lead, $product, $variant, 'crq-g1')
                ->create(['status' => Generation::STATUS_SUCCEEDED]);
            $stored = app(MediaStorage::class)->storeResult(
                (int) $this->account->id, (int) $this->site->id, (int) $gen->id, 'RESULT-g1', 'image/png',
            );
            $gen->forceFill(['result_image_path' => $stored->path])->save();

            Livewire::test(Gallery::class)
                ->assertOk()
                ->assertSee('Red Sneaker');
        });
    }

    public function test_gallery_empty_state_when_no_try_ons(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(Gallery::class)
                ->assertOk()
                ->assertSee(__('settings.gallery.empty'));
        });
    }

    // ---------------------------------------------------------------- M9 privacy

    public function test_privacy_form_persists_a_valid_patch(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(PrivacySettings::class)
                ->set('retentionDays', '90')
                ->set('freeGenerations', '3')
                ->set('showInGallery', false)
                ->set('blurSourcePhoto', true)
                ->call('save')
                ->assertHasNoErrors();

            $fresh = $this->site->fresh();
            $this->assertSame(90, $fresh->retention_days);
            $this->assertSame(3, $fresh->free_generations_before_signup);
            $this->assertFalse((bool) ($fresh->gallery_settings['show_in_gallery'] ?? true));
            $this->assertTrue((bool) ($fresh->privacy_config['blur_source_photo'] ?? false));
        });
    }

    public function test_until_delete_retention_maps_to_null(): void
    {
        $this->asMerchant(function (): void {
            Livewire::test(PrivacySettings::class)
                ->set('retentionDays', 'until_delete')
                ->set('freeGenerations', '')
                ->call('save')
                ->assertHasNoErrors();

            $fresh = $this->site->fresh();
            $this->assertNull($fresh->retention_days);
            $this->assertNull($fresh->free_generations_before_signup);
        });
    }

    public function test_invalid_free_generations_is_a_field_error_not_a_500(): void
    {
        $this->asMerchant(function (): void {
            // The whole patch is validated BEFORE any write, so seed a distinct retention
            // first to prove the bad-save persists NOTHING (not even the valid fields).
            $this->site->forceFill(['retention_days' => 7])->save();

            // A non-numeric free-generations value is coerced to -1, which the service
            // rejects with InvalidSiteSettingsException → a field error (no 500, no save).
            Livewire::test(PrivacySettings::class)
                ->set('retentionDays', '90')
                ->set('freeGenerations', 'abc')
                ->call('save')
                ->assertHasErrors('freeGenerations');

            // Validate-then-persist: the rejected patch wrote nothing — retention is still 7,
            // NOT the 90 the form tried to set alongside the bad free-generations value.
            $this->assertSame(7, $this->site->fresh()->retention_days);
        });
    }

    public function test_privacy_form_only_resolves_the_merchants_own_site(): void
    {
        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();

        $this->asMerchant(function () use ($otherSite): void {
            // A ?site= deep-link to a FOREIGN site resolves to null (global scope),
            // so the form falls back to the account's own first site — never the foreign one.
            request()->merge(['site' => $otherSite->id]);

            $page = Livewire::test(PrivacySettings::class);

            $this->assertNotSame($otherSite->id, $page->instance()->siteId);
            $this->assertSame($this->site->id, $page->instance()->siteId);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
