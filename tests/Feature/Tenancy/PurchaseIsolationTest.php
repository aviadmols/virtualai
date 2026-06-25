<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Credits\IdempotencyKey;
use App\Domain\Credits\Payments\PurchaseReconciler;
use App\Domain\Credits\Payments\PurchaseResult;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\CreditPurchase;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant-isolation gate for the PLATFORM-REVENUE rail (release blocker). credit_purchases
 * is BelongsToAccount (NOT on the global allow-list): account B can never read account A's
 * purchases; a webhook for A's provider_ref credits A and ONLY A (the account is resolved
 * from OUR row, never the webhook body); the purchase idempotency key carries account_id.
 */
class PurchaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_purchase_is_not_on_the_global_allow_list(): void
    {
        $this->assertFalse(GlobalModels::isGlobal(CreditPurchase::class));
    }

    public function test_account_b_cannot_read_account_a_purchases(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        CreditPurchase::factory()->forAccount($accountA)->count(2)->create();
        CreditPurchase::factory()->forAccount($accountB)->count(1)->create();

        $seenAsA = Tenant::run($accountA, fn () => CreditPurchase::query()->get());
        $this->assertCount(2, $seenAsA);
        $this->assertTrue($seenAsA->every(fn (CreditPurchase $p) => $p->account_id === $accountA->id));

        $seenAsB = Tenant::run($accountB, fn () => CreditPurchase::query()->get());
        $this->assertCount(1, $seenAsB);

        // B cannot fetch one of A's purchases by id.
        $aId = $seenAsA->first()->id;
        $crossRead = Tenant::run($accountB, fn () => CreditPurchase::query()->where('id', $aId)->first());
        $this->assertNull($crossRead);
    }

    public function test_unbound_purchase_query_fails_closed(): void
    {
        CreditPurchase::factory()->forAccount(Account::factory()->create())->create();

        Tenant::clear();
        $this->assertCount(0, CreditPurchase::query()->get());
    }

    public function test_a_webhook_for_account_a_never_credits_account_b(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $openingB = $accountB->fresh()->balance_micro_usd;

        // A's pending purchase.
        $purchaseA = CreditPurchase::factory()->forAccount($accountA)->state([
            'credits_micro_usd' => 9_000_000,
            'amount_usd' => 9.00,
        ])->create();

        // The webhook carries A's provider_ref. The reconciler resolves the account from
        // OUR row (A), never the body — so B is never touched.
        $result = PurchaseResult::make('payplus', $purchaseA->provider_ref, PurchaseResult::STATUS_PAID, 9_000_000);
        $reconciled = app(PurchaseReconciler::class)->reconcile($result);

        $this->assertSame($accountA->id, $reconciled->account_id);

        // A credited, B untouched.
        $this->assertSame(9_000_000, Tenant::run($accountA, fn () => CreditLedger::query()->where('type', 'purchase')->sum('amount_micro_usd')));
        $this->assertSame(0, Tenant::run($accountB, fn () => CreditLedger::query()->where('type', 'purchase')->count()));
        $this->assertSame($openingB, $accountB->fresh()->balance_micro_usd);
    }

    public function test_purchase_idempotency_key_carries_account_id(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        $keyA = IdempotencyKey::forPurchase($accountA->id, 'payplus', 'ref-shared');
        $keyB = IdempotencyKey::forPurchase($accountB->id, 'payplus', 'ref-shared');

        // Same provider_ref, DIFFERENT accounts -> different keys (no cross-account collision).
        $this->assertNotSame($keyA, $keyB);
        $this->assertSame("purchase:{$accountA->id}:payplus:ref-shared", $keyA);
    }
}
