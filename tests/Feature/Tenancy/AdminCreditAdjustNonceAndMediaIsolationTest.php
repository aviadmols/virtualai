<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Gallery\MerchantGalleryQuery;
use App\Domain\Media\MediaStorage;
use App\Filament\Platform\Resources\AccountResource\Pages\ListAccounts;
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
 * Phase 8 Wave-2 isolation re-audit — MONEY-SAFETY (saas-credits-billing lane).
 *
 * Two release-blocker-class proofs:
 *
 * 1) ADMIN CREDIT-ADJUST IDEMPOTENCY. The prior gate flagged a per-call-UUID race:
 *    a double-submitted admin adjust would mint a fresh UUID per call and write TWO
 *    ledger rows. The fix is a STABLE per-form-open nonce (a Hidden field defaulted
 *    once on modal open) used as the idempotency reference. This proves a double
 *    submit carrying the SAME nonce collapses to ONE append-only adjustment row
 *    (balance moved once), through CreditLedgerService — never a bare balance write.
 *    A faithful Livewire table-action call drives the real form -> action handler.
 *
 * 2) MEDIA DEGRADATION CANNOT LEAK. A thrown storage exception while resolving a
 *    result thumbnail (gallery / lead card) must degrade to a `purged` placeholder
 *    (objectExists=false, url=null) — it can NEVER surface another account's object
 *    or a signed URL. Proven by forcing MediaStorage::exists() to throw and asserting
 *    the item is purged with a null URL (no foreign object, no 500).
 */
class AdminCreditAdjustNonceAndMediaIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    // === (1) admin credit-adjust nonce ===

    /** The form data a double-submit re-sends: the SAME per-form-open nonce both times. */
    private function adjustFormData(string $nonce, float $amountUsd, string $reference = ''): array
    {
        return [
            'idempotency_nonce' => $nonce,
            'amount_usd' => $amountUsd,
            'reference' => $reference,
            'description' => 'audit adjust',
        ];
    }

    public function test_double_submit_with_a_stable_nonce_writes_exactly_one_ledger_row(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Filament::setCurrentPanel(Filament::getPanel('platform'));

        $account = Account::factory()->create(); // $5 opening grant

        // A single per-form-open nonce (what the Hidden field defaults to once); a
        // double-submit re-sends this SAME value, so the action must collapse to one row.
        $nonce = 'nonce-stable-001';
        $data = $this->adjustFormData($nonce, 2.0);

        // Fire the real table action twice with identical form data (the resubmit race).
        Livewire::test(ListAccounts::class)
            ->callTableAction('adjust', $account, $data)
            ->callTableAction('adjust', $account, $data);

        $rows = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_ADJUSTMENT)->get());

        // Exactly ONE adjustment row; the balance moved once (not twice).
        $this->assertCount(1, $rows, 'a double-submit with a stable nonce double-wrote the ledger');
        $this->assertSame(self::FIVE_DOLLARS_MICRO + 2_000_000, $account->fresh()->balance_micro_usd);
    }

    public function test_a_typed_reference_anchors_idempotency_over_the_nonce(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Filament::setCurrentPanel(Filament::getPanel('platform'));

        $account = Account::factory()->create();

        // Two DIFFERENT form opens (distinct nonces) but the SAME typed operator
        // reference must still collapse to one row (the reference wins as the anchor).
        Livewire::test(ListAccounts::class)
            ->callTableAction('adjust', $account, $this->adjustFormData('nonce-A', 2.0, 'audit-ref-7'))
            ->callTableAction('adjust', $account, $this->adjustFormData('nonce-B', 2.0, 'audit-ref-7'));

        $count = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_ADJUSTMENT)->count());

        $this->assertSame(1, $count);
        $this->assertSame(self::FIVE_DOLLARS_MICRO + 2_000_000, $account->fresh()->balance_micro_usd);
    }

    public function test_distinct_form_opens_are_distinct_adjustments(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Filament::setCurrentPanel(Filament::getPanel('platform'));

        $account = Account::factory()->create();

        // Two SEPARATE modal opens (distinct nonces, no typed ref) are two genuinely
        // different adjustments — they must NOT collapse (the nonce only de-dupes a
        // resubmit of the SAME open).
        Livewire::test(ListAccounts::class)
            ->callTableAction('adjust', $account, $this->adjustFormData('open-1', 1.0))
            ->callTableAction('adjust', $account, $this->adjustFormData('open-2', 1.0));

        $count = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_ADJUSTMENT)->count());

        $this->assertSame(2, $count);
        $this->assertSame(self::FIVE_DOLLARS_MICRO + 2_000_000, $account->fresh()->balance_micro_usd);
    }

    public function test_the_adjust_action_anchors_idempotency_on_the_nonce_not_a_per_call_uuid(): void
    {
        // Anti-regression guard on the fix's MECHANISM (a source assertion, the same shape
        // as the withoutGlobalScope grep wall): the adjust action must (a) carry a Hidden
        // idempotency_nonce defaulted once per form open, and (b) pass that nonce (or the
        // typed reference) as the idempotency reference — NEVER mint a fresh per-call UUID
        // at the call site (the prior gate's race). If someone reverts to a per-call UUID
        // here, this goes red.
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/Filament/Platform/Resources/AccountResource.php'
        );

        // (a) the stable hidden anchor with a default closure (evaluated once per open).
        $this->assertMatchesRegularExpression(
            "/Hidden::make\(\s*'idempotency_nonce'\s*\)\s*->default\(/s",
            $source,
            'the adjust form is missing the stable per-form-open idempotency_nonce anchor',
        );

        // (b) the action passes the nonce (or the typed reference) as the idempotency ref.
        $this->assertStringContainsString(
            "\$data['reference'] ?: \$data['idempotency_nonce']",
            $source,
            'the adjust action must anchor idempotency on the stable nonce/reference, not a per-call UUID',
        );

        // (c) the call site does NOT mint a per-call UUID for the reference (the prior bug).
        $this->assertDoesNotMatchRegularExpression(
            "/reference:\s*\(string\)\s*Str::uuid\(\)/",
            $source,
            'a per-call UUID reference reintroduces the double-adjust race',
        );
    }

    // === (2) media degradation cannot leak a foreign object ===

    public function test_a_thrown_storage_exception_degrades_to_purged_never_a_foreign_object(): void
    {
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');

        // Account A owns the generation whose result we will fail to resolve.
        $accountA = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();

        // Account B owns a DIFFERENT stored result — the object that must never surface
        // when A's resolution throws.
        $accountB = Account::factory()->create();
        $siteB = Site::factory()->forAccount($accountB)->create();

        [$genA] = $this->seedSucceededGeneration($accountA, $siteA, 'crq-a');
        $this->seedSucceededGeneration($accountB, $siteB, 'crq-b');

        // Force the storage-failure path WITHOUT mocking the final MediaStorage: point the
        // media disk at an UNCONFIGURED disk name. The real MediaStorage::exists() then
        // resolves Storage::disk('__broken__'), which throws — exactly the "disk unreachable"
        // / driver-error case the gallery's resolveThumbnail() must swallow into `purged`.
        config()->set('trayon.media.disk', '__broken__');

        $items = app(MerchantGalleryQuery::class)->forSite($siteA);

        // The gallery degraded A's tile to purged with a null URL — NOT B's object, NOT a 500.
        $this->assertCount(1, $items);
        $this->assertTrue($items->first()->purged, 'a storage failure must degrade to purged');
        $this->assertNull($items->first()->resultThumbnailUrl, 'no signed URL on a failed resolution');
        $this->assertSame((int) $genA->id, $items->first()->generationId, 'the tile is A\'s own generation');
    }

    /**
     * A succeeded generation with a stored result image under $account/$site.
     *
     * @return array{0: Generation}
     */
    private function seedSucceededGeneration(Account $account, Site $site, string $crq): array
    {
        $gen = Tenant::run($account, function () use ($account, $site, $crq) {
            $product = Product::factory()->forSite($site)->confirmed()->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();
            $lead = EndUser::factory()->forSite($site)->create();

            $gen = Generation::factory()->forContext($lead, $product, $variant, $crq)
                ->create(['status' => Generation::STATUS_SUCCEEDED]);

            $stored = app(MediaStorage::class)->storeResult(
                (int) $account->id, (int) $site->id, (int) $gen->id, 'BYTES-'.$crq, 'image/png',
            );
            $gen->forceFill(['result_image_path' => $stored->path])->save();

            return $gen;
        });

        return [$gen];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
