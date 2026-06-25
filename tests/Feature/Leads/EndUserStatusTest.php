<?php

namespace Tests\Feature\Leads;

use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * EndUser lead-funnel status is FORWARD-ONLY and guarded. The funnel advances
 * new -> generated -> added_to_cart -> purchased, and any state can drop to
 * incomplete; a backwards or skipping move throws (purchased is terminal-best).
 */
class EndUserStatusTest extends TestCase
{
    use RefreshDatabase;

    private function endUser(): EndUser
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        return EndUser::factory()->forSite($site)->create();
    }

    public function test_funnel_advances_forward(): void
    {
        $endUser = $this->endUser();

        $endUser->transitionTo(EndUser::STATUS_GENERATED);
        $this->assertSame(EndUser::STATUS_GENERATED, $endUser->status);

        $endUser->transitionTo(EndUser::STATUS_ADDED_TO_CART);
        $endUser->transitionTo(EndUser::STATUS_PURCHASED);
        $this->assertSame(EndUser::STATUS_PURCHASED, $endUser->fresh()->status);
    }

    public function test_backwards_move_throws(): void
    {
        $endUser = $this->endUser();
        $endUser->transitionTo(EndUser::STATUS_GENERATED);

        $this->expectException(RuntimeException::class);
        $endUser->transitionTo(EndUser::STATUS_NEW); // backwards -> illegal
    }

    public function test_skipping_a_stage_throws(): void
    {
        $endUser = $this->endUser();

        $this->expectException(RuntimeException::class);
        $endUser->transitionTo(EndUser::STATUS_PURCHASED); // new -> purchased skips stages
    }

    public function test_any_state_can_drop_to_incomplete(): void
    {
        $endUser = $this->endUser();
        $endUser->transitionTo(EndUser::STATUS_GENERATED);
        $endUser->transitionTo(EndUser::STATUS_INCOMPLETE);

        $this->assertSame(EndUser::STATUS_INCOMPLETE, $endUser->fresh()->status);
    }

    public function test_purchased_is_terminal_best(): void
    {
        $endUser = $this->endUser();
        $endUser->transitionTo(EndUser::STATUS_GENERATED);
        $endUser->transitionTo(EndUser::STATUS_ADDED_TO_CART);
        $endUser->transitionTo(EndUser::STATUS_PURCHASED);

        // Only -> incomplete is allowed out of purchased; no further advance exists.
        $this->expectException(RuntimeException::class);
        $endUser->transitionTo(EndUser::STATUS_ADDED_TO_CART);
    }

    public function test_same_state_is_an_idempotent_noop(): void
    {
        $endUser = $this->endUser();
        $endUser->transitionTo(EndUser::STATUS_GENERATED);

        // Re-marking the same state does not throw.
        $endUser->transitionTo(EndUser::STATUS_GENERATED);
        $this->assertSame(EndUser::STATUS_GENERATED, $endUser->status);
    }
}
