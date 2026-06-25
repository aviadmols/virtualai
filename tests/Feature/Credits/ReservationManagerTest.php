<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\Reservation;
use App\Domain\Credits\ReservationManager;
use App\Models\Account;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ReservationManager — the in-flight credit hold behind the money path.
 *
 * Phase-6 pre-work S1: release() must be ATOMIC. A concurrent double-release (the
 * failure path racing a finalize, or two workers) must decrement reserved_micro_usd
 * EXACTLY once, never twice. The pull()-based claim proves it here.
 */
class ReservationManagerTest extends TestCase
{
    use RefreshDatabase;

    private const ESTIMATE = 1_500_000;
    private const FIVE_DOLLARS_MICRO = 5_000_000;

    private ReservationManager $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservations = app(ReservationManager::class);
    }

    public function test_reserve_holds_then_release_frees_the_column(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $reservation = $this->reservations->reserve($account, 'gen:key:1', self::ESTIMATE);
            $this->assertSame(self::ESTIMATE, $account->fresh()->reserved_micro_usd);

            $this->reservations->release($reservation);
            $this->assertSame(0, $account->fresh()->reserved_micro_usd);
        });
    }

    public function test_concurrent_double_release_decrements_reserved_exactly_once(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $reservation = $this->reservations->reserve($account, 'gen:key:2', self::ESTIMATE);
            $this->assertSame(self::ESTIMATE, $account->fresh()->reserved_micro_usd);

            // Two releases of the SAME reservation (the S1 race). pull() is atomic
            // get-and-delete: only the first wins the key, so reserved drops by the
            // estimate ONCE — never to a negative double-decrement.
            $this->reservations->release($reservation);
            $this->reservations->release($reservation);

            $this->assertSame(0, $account->fresh()->reserved_micro_usd);
        });
    }

    public function test_re_reserve_same_key_is_a_no_op_not_a_double_hold(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            // Same in-flight key reserved twice (a retried dispatch). add() is
            // put-if-absent, so the column is incremented only on the first claim.
            $this->reservations->reserve($account, 'gen:key:3', self::ESTIMATE);
            $this->reservations->reserve($account, 'gen:key:3', self::ESTIMATE);

            $this->assertSame(self::ESTIMATE, $account->fresh()->reserved_micro_usd);
        });
    }

    public function test_release_of_a_never_held_reservation_is_a_no_op(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            // A reservation object whose key was never claimed in the cache.
            $phantom = Reservation::forKey($account->id, 'gen:key:never-held', self::ESTIMATE);
            $this->reservations->release($phantom);

            // Nothing to release: reserved stays 0, balance untouched.
            $this->assertSame(0, $account->fresh()->reserved_micro_usd);
            $this->assertSame(self::FIVE_DOLLARS_MICRO, $account->fresh()->balance_micro_usd);
        });
    }
}
