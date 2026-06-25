<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\IdempotencyKey;
use App\Domain\Credits\Payments\PurchaseInitiator;
use App\Domain\Credits\Payments\PurchaseReconciler;
use App\Domain\Credits\Payments\PurchaseResult;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\CreditPurchase;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The credit-PURCHASE rail (platform revenue): initiate a top-up via a MOCKED PayPlus
 * page, then the IDEMPOTENT webhook that writes exactly one `purchase` ledger row. No
 * real PayPlus call is ever made; the HTTP client is faked and the webhook is built +
 * signed locally with the configured secret.
 *
 * The two money rails stay separate: credit_purchases (inbound payment) vs credit_ledger
 * (merchant spend), linked 1:1 by ledger_id. A paid webhook credits FACE VALUE (markup
 * is on spend, not purchase). A replayed webhook is a no-op (the ledger_id wall).
 */
class PurchaseRailTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_payplus_secret';
    private const PAGE_LINK = 'https://payments.payplus.example/page/abc123';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.payplus', [
            'api_key' => 'test_api_key',
            'secret_key' => self::SECRET,
            'page_uid' => 'test_page_uid',
            'base_url' => 'https://restapi.payplus.example/api/v1.0',
            'currency' => 'USD',
            'timeout' => 10,
        ]);
    }

    /** A merchant tops up $10; the provider returns a redirect; a pending row is persisted. */
    public function test_initiate_returns_a_payplus_redirect_and_persists_a_pending_purchase(): void
    {
        Http::fake([
            '*/PaymentPages/generateLink' => Http::response([
                'results' => ['status' => 'success', 'code' => 0],
                'data' => ['payment_page_link' => self::PAGE_LINK, 'page_request_uid' => 'pru_1'],
            ], 200),
        ]);

        $account = Account::factory()->create();
        $amountMicro = 10_000_000; // $10.00 in micro-USD

        $intent = Tenant::run($account, fn () => app(PurchaseInitiator::class)->initiate($account, $amountMicro));

        $this->assertTrue($intent->ok);
        $this->assertSame(self::PAGE_LINK, $intent->redirectUrl);
        $this->assertSame('payplus', $intent->provider);
        $this->assertSame($amountMicro, $intent->amountMicroUsd);

        // Exactly one pending purchase row, FACE-VALUE credits, deterministic key.
        $purchase = Tenant::run($account, fn () => CreditPurchase::query()->first());
        $this->assertNotNull($purchase);
        $this->assertSame(CreditPurchase::STATUS_PENDING, $purchase->status);
        $this->assertSame($amountMicro, $purchase->credits_micro_usd);
        $this->assertNull($purchase->ledger_id);
        $this->assertSame(
            IdempotencyKey::forPurchase($account->id, 'payplus', $purchase->provider_ref),
            $purchase->idempotency_key,
        );
    }

    /** Webhook happy path: a signed approved webhook writes ONE purchase ledger row; balance rises. */
    public function test_webhook_happy_path_writes_one_purchase_ledger_row(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(10_000_000);
        $openingBalance = $account->fresh()->balance_micro_usd; // $5 opening grant

        $result = $this->verifiedResult($purchase, PurchaseResult::STATUS_PAID, 10_000_000);
        $reconciled = app(PurchaseReconciler::class)->reconcile($result);

        $this->assertNotNull($reconciled);
        $this->assertSame(CreditPurchase::STATUS_PAID, $reconciled->status);
        $this->assertNotNull($reconciled->ledger_id);
        $this->assertNotNull($reconciled->paid_at);

        // Exactly one `purchase` ledger row, linked 1:1, at face value.
        $purchaseRows = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->get());
        $this->assertCount(1, $purchaseRows);
        $this->assertSame(10_000_000, $purchaseRows->first()->amount_micro_usd);
        $this->assertSame($reconciled->ledger_id, $purchaseRows->first()->id);

        // Balance rose by the face value (opening grant + $10 top-up).
        $this->assertSame($openingBalance + 10_000_000, $account->fresh()->balance_micro_usd);
    }

    /** IDEMPOTENT: the same provider_ref reconciled twice writes exactly ONE purchase row. */
    public function test_webhook_is_idempotent_same_provider_ref_credits_once(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(7_000_000);
        $openingBalance = $account->fresh()->balance_micro_usd;

        $result = $this->verifiedResult($purchase, PurchaseResult::STATUS_PAID, 7_000_000);

        $first = app(PurchaseReconciler::class)->reconcile($result);
        $second = app(PurchaseReconciler::class)->reconcile($result); // replay

        // Same purchase row, same ledger link — credited once.
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->ledger_id, $second->ledger_id);

        $purchaseRows = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(1, $purchaseRows);

        // Balance rose exactly once.
        $this->assertSame($openingBalance + 7_000_000, $account->fresh()->balance_micro_usd);
    }

    /** A declined/failed payment writes NO credits and marks the purchase failed. */
    public function test_declined_payment_writes_no_credits(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(5_000_000);
        $openingBalance = $account->fresh()->balance_micro_usd;

        $result = $this->verifiedResult($purchase, PurchaseResult::STATUS_FAILED, 5_000_000);
        $reconciled = app(PurchaseReconciler::class)->reconcile($result);

        $this->assertSame(CreditPurchase::STATUS_FAILED, $reconciled->status);
        $this->assertNull($reconciled->ledger_id);

        $purchaseRows = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(0, $purchaseRows);
        $this->assertSame($openingBalance, $account->fresh()->balance_micro_usd);
    }

    /** A purchase that was already paid then refunded does NOT auto-claw the ledger row. */
    public function test_refund_after_paid_does_not_silently_claw_the_ledger(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(4_000_000);

        // Pay it.
        app(PurchaseReconciler::class)->reconcile($this->verifiedResult($purchase, PurchaseResult::STATUS_PAID, 4_000_000));
        $balanceAfterPaid = $account->fresh()->balance_micro_usd;

        // A later refund webhook hits the ledger_id wall (already credited) -> no-op on the ledger.
        $reconciled = app(PurchaseReconciler::class)->reconcile($this->verifiedResult($purchase, PurchaseResult::STATUS_REFUNDED, 4_000_000));

        // Still exactly one purchase row; the refund is NOT auto-clawed here (a separate decision).
        $purchaseRows = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(1, $purchaseRows);
        $this->assertSame($balanceAfterPaid, $account->fresh()->balance_micro_usd);
        $this->assertSame(CreditPurchase::STATUS_PAID, $reconciled->status); // unchanged by the wall
    }

    /**
     * DEFENSE IN DEPTH: a verified PAID webhook whose provider-confirmed amount does NOT
     * match what we recorded on initiate is parked as amount_mismatch and writes NO ledger
     * row — never silently credited.
     */
    public function test_amount_mismatch_writes_no_credits_and_parks_for_review(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(5_000_000); // we recorded $5
        $openingBalance = $account->fresh()->balance_micro_usd;

        // The (signed) webhook confirms a DIFFERENT amount ($4) — anomaly.
        $result = $this->verifiedResult($purchase, PurchaseResult::STATUS_PAID, 4_000_000);
        $reconciled = app(PurchaseReconciler::class)->reconcile($result);

        $this->assertSame(CreditPurchase::STATUS_AMOUNT_MISMATCH, $reconciled->status);
        $this->assertNull($reconciled->ledger_id);

        $purchaseRows = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(0, $purchaseRows);
        $this->assertSame($openingBalance, $account->fresh()->balance_micro_usd);
    }

    // === Helpers ===

    /** Seed an account with a pending purchase row (as initiate() would). */
    private function seedPendingPurchase(int $amountMicro): array
    {
        $account = Account::factory()->create();
        $purchase = CreditPurchase::factory()->forAccount($account)->state([
            'credits_micro_usd' => $amountMicro,
            'amount_usd' => round($amountMicro / 1_000_000, 2),
        ])->create();

        return [$account, $purchase];
    }

    /** Build a verified PurchaseResult exactly as PayPlusProvider would after verifying. */
    private function verifiedResult(CreditPurchase $purchase, string $status, int $amountMicro): PurchaseResult
    {
        return PurchaseResult::make(
            provider: 'payplus',
            providerRef: $purchase->provider_ref,
            status: $status,
            amountMicroUsd: $amountMicro,
        );
    }
}
