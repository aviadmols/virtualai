<?php

namespace App\Domain\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * QueueHealth — a fail-soft snapshot of the background-work plane for the platform
 * dashboard: is a Horizon worker running, how many jobs are waiting per queue, and how
 * many have failed. Every probe is wrapped so a missing worker / unreachable Redis
 * NEVER breaks the dashboard — it just reports "unknown / inactive" (which is itself the
 * signal the super-admin needs). The full control panel is Horizon's own UI at /horizon.
 */
final class QueueHealth
{
    /**
     * @return array{worker_active: bool, pending: int, failed: int, per_queue: array<string,int>}
     */
    public function snapshot(): array
    {
        $perQueue = $this->perQueue();

        return [
            'worker_active' => $this->workerActive(),
            'pending' => array_sum($perQueue),
            'failed' => $this->failedCount(),
            'per_queue' => $perQueue,
        ];
    }

    /** True if at least one Horizon master supervisor is currently reporting in. */
    public function workerActive(): bool
    {
        try {
            return count(app(MasterSupervisorRepository::class)->all()) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Waiting (not-yet-processed) job count per canonical queue. Reads the queue names
     * from config (config:cache-safe) — never the bare QUEUE_* constants.
     *
     * @return array<string,int>
     */
    public function perQueue(): array
    {
        $sizes = [];

        foreach ((array) config('trayon.queues', []) as $name) {
            try {
                $sizes[(string) $name] = Queue::size((string) $name);
            } catch (Throwable) {
                $sizes[(string) $name] = 0;
            }
        }

        return $sizes;
    }

    /** Failed-job count (the failed_jobs table). */
    public function failedCount(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
