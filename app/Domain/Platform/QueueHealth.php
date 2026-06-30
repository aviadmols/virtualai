<?php

namespace App\Domain\Platform;

use Illuminate\Support\Facades\Cache;
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
    // === CONSTANTS ===
    // A running queue worker (queue:work OR Horizon) stamps this cache key on every poll
    // loop (Queue::looping, registered in AppServiceProvider) — even when idle.
    public const HEARTBEAT_KEY = 'worker:heartbeat';
    public const HEARTBEAT_TTL = 120;       // seconds a heartbeat counts as "alive"
    public const HEARTBEAT_THROTTLE = 20;   // min seconds between heartbeat writes

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

    /**
     * True if a queue worker is alive: a fresh heartbeat (the in-container queue:work or a
     * Horizon worker stamps it each poll loop), or — as a fallback — a Horizon master
     * supervisor reporting in. Covers both the queue:work and Horizon deployment shapes.
     */
    public function workerActive(): bool
    {
        try {
            $beat = (int) Cache::get(self::HEARTBEAT_KEY, 0);
            if ($beat > 0 && (now()->timestamp - $beat) < self::HEARTBEAT_TTL) {
                return true;
            }
        } catch (Throwable) {
            // cache unavailable — fall through to the Horizon master check
        }

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
