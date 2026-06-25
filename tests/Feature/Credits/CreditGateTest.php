<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\CreditDenied;
use App\Domain\Credits\CreditGate;
use App\Domain\Credits\ReservationManager;
use App\Models\Account;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CreditGate — the MERCHANT-credit gate. A denial is a TYPED CreditDenied result
 * ("out of credits" UI signal), never a thrown exception / 500. Pass iff the
 * account is active and spendable (balance − reserved) ≥ estimate.
 */
class CreditGateTest extends TestCase
{
    use RefreshDatabase;

    private const FIVE_DOLLARS_MICRO = 5_000_000;

    public function test_passes_when_spendable_covers_the_estimate(): void
    {
        $account = Account::factory()->create(); // $5

        $result = CreditGate::for($account)->assertCanSpend(1_000_000);

        $this->assertInstanceOf(CreditDenied::class, $result);
        $this->assertTrue($result->passed);
        $this->assertNull($result->reason);
    }

    public function test_denies_with_typed_result_not_an_exception_when_out_of_credits(): void
    {
        $account = Account::factory()->create(); // $5

        // Estimate exceeds the $5 balance.
        $result = CreditGate::for($account)->assertCanSpend(self::FIVE_DOLLARS_MICRO + 1);

        // It is a typed result, NOT a thrown 500.
        $this->assertInstanceOf(CreditDenied::class, $result);
        $this->assertTrue($result->denied());
        $this->assertSame(CreditDenied::REASON_INSUFFICIENT_CREDITS, $result->reason);
        $this->assertSame(self::FIVE_DOLLARS_MICRO, $result->spendableMicroUsd);
    }

    public function test_reservation_reduces_spendable_so_the_gate_denies(): void
    {
        $account = Account::factory()->create(); // $5
        $reservations = app(ReservationManager::class);

        Tenant::run($account, function () use ($account, $reservations) {
            // Hold $4 in flight; only $1 spendable remains.
            $reservations->reserve($account, 'gen-key-1', 4_000_000);
            $account->refresh();

            // A $2 estimate now exceeds the $1 spendable -> denied.
            $denied = CreditGate::for($account)->assertCanSpend(2_000_000);
            $this->assertTrue($denied->denied());
            $this->assertSame(CreditDenied::REASON_INSUFFICIENT_CREDITS, $denied->reason);

            // A $1 estimate still passes.
            $ok = CreditGate::for($account)->assertCanSpend(1_000_000);
            $this->assertTrue($ok->passed);
        });
    }

    public function test_suspended_account_is_denied_as_inactive(): void
    {
        $account = Account::factory()->suspended()->create(); // still has $5 from grant

        $result = CreditGate::for($account)->assertCanSpend(1_000_000);

        $this->assertTrue($result->denied());
        $this->assertSame(CreditDenied::REASON_ACCOUNT_INACTIVE, $result->reason);
    }

    public function test_low_balance_warning_threshold(): void
    {
        $account = Account::factory()->create(); // $5 -> above the $1 low threshold

        $this->assertFalse(CreditGate::for($account)->isLowBalance());

        // Drop spendable to exactly the threshold via a reservation.
        $reservations = app(ReservationManager::class);
        Tenant::run($account, function () use ($account, $reservations) {
            $reservations->reserve($account, 'gen-key-low', 4_000_000); // spendable now $1
            $account->refresh();
            $this->assertTrue(CreditGate::for($account)->isLowBalance());
        });
    }
}
