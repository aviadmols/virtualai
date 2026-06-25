<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ActivityRecorder SWALLOWS its own exceptions — a failed trace write must NEVER
 * block or roll back the money path. It records account-scoped events.
 */
class ActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_an_event_scoped_to_the_bound_account(): void
    {
        $account = Account::factory()->create();
        $recorder = app(ActivityRecorder::class);

        $event = Tenant::run($account, fn () => $recorder->record(
            kind: ActivityEvent::KIND_CREDIT_CHARGED,
            subject: $account,
            details: ['amount_micro_usd' => -1_000_000],
        ));

        $this->assertNotNull($event);
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame(ActivityEvent::KIND_CREDIT_CHARGED, $event->kind);
    }

    public function test_a_failed_write_is_swallowed_and_returns_null(): void
    {
        $account = Account::factory()->create();
        $recorder = app(ActivityRecorder::class);

        // Force the insert to fail by dropping the table mid-test: the recorder must
        // NOT throw — it returns null and the caller (the money path) carries on.
        Tenant::run($account, function () use ($recorder) {
            DB::statement('DROP TABLE activity_events');

            $result = $recorder->record(ActivityEvent::KIND_OPENING_GRANT);
            $this->assertNull($result); // swallowed, not thrown
        });
    }
}
