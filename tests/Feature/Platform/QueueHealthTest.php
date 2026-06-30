<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_worker_is_inactive_with_no_heartbeat_and_no_horizon(): void
    {
        // No worker heartbeat + no Horizon master → inactive, no exception.
        $this->assertFalse(app(QueueHealth::class)->workerActive());
    }

    public function test_worker_is_active_when_a_fresh_heartbeat_exists(): void
    {
        // A running queue worker stamps the heartbeat each poll loop.
        Cache::put(QueueHealth::HEARTBEAT_KEY, now()->timestamp, QueueHealth::HEARTBEAT_TTL);

        $this->assertTrue(app(QueueHealth::class)->workerActive());
    }

    public function test_worker_is_inactive_when_the_heartbeat_is_stale(): void
    {
        // A heartbeat older than the TTL means the worker stopped.
        Cache::put(
            QueueHealth::HEARTBEAT_KEY,
            now()->timestamp - (QueueHealth::HEARTBEAT_TTL + 30),
            QueueHealth::HEARTBEAT_TTL + 120,
        );

        $this->assertFalse(app(QueueHealth::class)->workerActive());
    }
}
