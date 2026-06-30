<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QueueHealth — the dashboard's background-work snapshot must be FAIL-SOFT: even with no
 * Horizon worker and no reachable Redis it returns a complete, valid shape (so the
 * dashboard renders and simply reports "inactive"), never throwing.
 */
class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_returns_a_complete_failsoft_shape(): void
    {
        $snapshot = app(QueueHealth::class)->snapshot();

        $this->assertIsBool($snapshot['worker_active']);
        $this->assertIsInt($snapshot['pending']);
        $this->assertIsInt($snapshot['failed']);
        $this->assertIsArray($snapshot['per_queue']);

        // The canonical work-type queues are all probed (from config, config:cache-safe).
        foreach (['generations', 'scans', 'webhooks', 'media', 'default'] as $queue) {
            $this->assertArrayHasKey($queue, $snapshot['per_queue']);
        }

        // pending is the sum of the per-queue waiting counts.
        $this->assertSame(array_sum($snapshot['per_queue']), $snapshot['pending']);
    }

    public function test_worker_is_reported_inactive_with_no_running_horizon(): void
    {
        // No Horizon master is running in the test process → inactive, no exception.
        $this->assertFalse(app(QueueHealth::class)->workerActive());
    }
}
