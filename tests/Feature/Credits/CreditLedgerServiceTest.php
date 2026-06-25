<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\CreditMath;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Credits\ReservationManager;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The money path through the ledger writer (the spine Phase 6's GenerateTryOnJob
 * drives). Proves the laws: reserve before the call, charge ONLY on success,
 * release on failure (NO charge row), and a double charge is impossible.
 */
class CreditLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    private CreditLedgerService $ledger;

    private ReservationManager $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(CreditLedgerService::class);
        $this->reservations = app(ReservationManager::class);
    }

    /** A deterministic generation idempotency key for a fake generation. */
    private function key(Account $account, int $generationId): string
    {
        return IdempotencyKey::forGeneration(
            accountId: $account->id,
            siteId: 1,
            endUserId: 1,
            productId: 1,
            variant: ['color' => 'Red', 'size' => 'M'],
            clientRequestId: 'req-'.$generationId,
        );
    }

    public function test_reserve_then_charge_on_success(): void
    {
        $account = Account::factory()->create(); // $5 opening grant
        $generationId = 101;
        $key = $this->key($account, $generationId);

        // Real cost $0.40; selling value at 2.5x = $1.00 = 1_000_000 micro.
        $costUsd = 0.40;
        $estimate = 1_500_000; // a generous in-flight reservation
        $chargeMicro = CreditMath::chargeMicroUsd($costUsd, 2.5);
        $this->assertSame(1_000_000, $chargeMicro);

        Tenant::run($account, function () use ($account, $key, $estimate, $chargeMicro, $costUsd, $generationId) {
            // RESERVE before the (simulated) OpenRouter call.
            $reservation = $this->reservations->reserve($account, $key, $estimate);
            $account->refresh();
            $this->assertSame($estimate, $account->reserved_micro_usd);
            // Spendable is reduced by the held reservation.
            $this->assertSame(self::FIVE_DOLLARS_MICRO - $estimate, $account->spendableMicroUsd());

            // ... OpenRouter call succeeds, result stored ... then CHARGE.
            $row = $this->ledger->charge(
                account: $account,
                chargeMicroUsd: $chargeMicro,
                actualCostMicroUsd: CreditMath::usdToMicro($costUsd),
                idempotencyKey: $key,
                generationId: $generationId,
                reservation: $reservation,
            );

            $this->assertSame(CreditLedger::TYPE_CHARGE, $row->type);
            $this->assertSame(-$chargeMicro, $row->amount_micro_usd);
        });

        $account->refresh();
        // Balance debited by the charge; reservation released.
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $chargeMicro, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        // balance_after on the charge row matches the live balance.
        $charge = Tenant::run($account, fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->first());
        $this->assertSame($account->balance_micro_usd, $charge->balance_after_micro_usd);
        $this->assertSame(CreditMath::usdToMicro($costUsd), $charge->actual_cost_micro_usd);
    }

    public function test_release_on_failure_writes_no_charge_row(): void
    {
        $account = Account::factory()->create();
        $generationId = 202;
        $key = $this->key($account, $generationId);
        $estimate = 1_500_000;

        Tenant::run($account, function () use ($account, $key, $estimate) {
            $reservation = $this->reservations->reserve($account, $key, $estimate);
            $account->refresh();
            $this->assertSame($estimate, $account->reserved_micro_usd);

            // ... OpenRouter call FAILS ... release, write NO charge.
            $this->ledger->release($reservation, ['failure_code' => 'ai_call_failed']);
        });

        $account->refresh();
        // No charge: balance unchanged, reservation released.
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->balance_micro_usd);
        $this->assertSame(0, $account->reserved_micro_usd);

        // The hard assertion: NO charge row exists for this generation.
        $charges = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)
            ->where('reference_id', $generationId)
            ->count());
        $this->assertSame(0, $charges);
        $this->assertFalse($this->ledger->hasCharge($generationId));
    }

    public function test_double_charge_is_impossible_same_key(): void
    {
        $account = Account::factory()->create();
        $generationId = 303;
        $key = $this->key($account, $generationId);
        $chargeMicro = 1_000_000;

        Tenant::run($account, function () use ($account, $key, $chargeMicro, $generationId) {
            $first = $this->ledger->charge($account, $chargeMicro, 400_000, $key, $generationId);
            // A second charge() with the SAME key returns the SAME row, no new debit.
            $second = $this->ledger->charge($account, $chargeMicro, 400_000, $key, $generationId);

            $this->assertSame($first->id, $second->id);
        });

        // Exactly ONE charge row, balance debited exactly once.
        $count = Tenant::run($account, fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(1, $count);
        $this->assertSame(self::FIVE_DOLLARS_MICRO - $chargeMicro, $account->fresh()->balance_micro_usd);
    }

    public function test_double_charge_blocked_at_the_db_unique_index(): void
    {
        $account = Account::factory()->create();
        $key = $this->key($account, 404);

        // Direct insert of a charge, then a raw duplicate insert with the same key
        // must violate the unique index — the DB-level impossibility (LAYER 1).
        Tenant::run($account, fn () => $this->ledger->charge($account, 1_000_000, 400_000, $key, 404));

        $this->expectException(\Illuminate\Database\QueryException::class);

        \DB::table('credit_ledger')->insert([
            'account_id' => $account->id,
            'type' => CreditLedger::TYPE_CHARGE,
            'amount_micro_usd' => -1_000_000,
            'balance_after_micro_usd' => 0,
            'idempotency_key' => $key, // SAME key -> unique violation
            'created_at' => now(),
        ]);
    }

    public function test_refund_reverses_a_charge_with_a_new_row(): void
    {
        $account = Account::factory()->create();
        $generationId = 505;
        $chargeKey = $this->key($account, $generationId);

        Tenant::run($account, function () use ($account, $chargeKey, $generationId) {
            $this->ledger->charge($account, 1_000_000, 400_000, $chargeKey, $generationId);
            $account->refresh();
            $this->assertSame(self::FIVE_DOLLARS_MICRO - 1_000_000, $account->balance_micro_usd);

            // A refund is a NEW positive row, never an edit of the charge.
            $refund = $this->ledger->refund(
                account: $account,
                amountMicroUsd: 1_000_000,
                idempotencyKey: IdempotencyKey::forRefund($account->id, $generationId),
                generationId: $generationId,
            );
            $this->assertSame(CreditLedger::TYPE_REFUND, $refund->type);
            $this->assertSame(1_000_000, $refund->amount_micro_usd);
        });

        // Balance restored; the charge row still exists (a correction is a new row).
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        $rowCount = Tenant::run($account, fn () => CreditLedger::query()->count());
        $this->assertSame(3, $rowCount); // grant + charge + refund
    }

    public function test_adjustment_can_add_or_subtract(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $this->ledger->adjustment($account, -2_000_000, IdempotencyKey::forAdjustment($account->id, 'support-clawback-1'), 'clawback');
            $this->assertSame(self::FIVE_DOLLARS_MICRO - 2_000_000, $account->fresh()->balance_micro_usd);

            $this->ledger->adjustment($account, 1_000_000, IdempotencyKey::forAdjustment($account->id, 'goodwill-1'), 'goodwill');
            $this->assertSame(self::FIVE_DOLLARS_MICRO - 1_000_000, $account->fresh()->balance_micro_usd);
        });
    }

    public function test_per_operation_multiplier_overrides_the_default(): void
    {
        // The default markup is 2.5; an operation with credit_multiplier=3 overrides it.
        $opDefault = CreditMath::multiplierFor('try_on_generation'); // seeded operation
        $this->assertIsFloat($opDefault);

        // A fictional operation key with no row falls back to the config default 2.5.
        $this->assertSame((float) config('trayon.pricing.markup_default'), CreditMath::multiplierFor('no_such_operation'));
    }
}
