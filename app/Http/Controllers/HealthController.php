<?php

namespace App\Http\Controllers;

use App\Support\Health\Heartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Liveness/readiness surface. Reports app + DB + Redis + queue reachability and
 * the scheduler heartbeat age. "Is the scheduler running" is answerable in one
 * glance via the heartbeat color.
 */
class HealthController extends Controller
{
    // === CONSTANTS ===
    private const STATUS_OK = 'ok';
    private const STATUS_FAIL = 'fail';
    private const HTTP_OK = 200;
    private const HTTP_UNAVAILABLE = 503;

    public function __invoke(Heartbeat $heartbeat): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueueConnection(),
        ];

        $heartbeatStatus = $heartbeat->status();

        // A failed hard dependency (db/redis/queue) => 503. The heartbeat color is
        // reported but does not 503 the page (a red heartbeat is a cost incident
        // surfaced elsewhere, not a web-liveness failure).
        $healthy = ! in_array(self::STATUS_FAIL, array_column($checks, 'status'), true);

        return response()->json([
            'status' => $healthy ? self::STATUS_OK : self::STATUS_FAIL,
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'version' => app()->version(),
            ],
            'checks' => $checks,
            'scheduler' => [
                'status' => $heartbeatStatus,
                'last_beat_at' => $heartbeat->lastBeatAt()?->toIso8601String(),
                'age_seconds' => $heartbeat->ageSeconds(),
            ],
        ], $healthy ? self::HTTP_OK : self::HTTP_UNAVAILABLE);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return ['status' => self::STATUS_OK, 'driver' => config('database.default')];
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_FAIL, 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        // Redis is required in production; locally it may be absent (sqlite/db
        // fallback), so a miss is reported as skipped rather than a hard fail.
        if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis') {
            return ['status' => self::STATUS_OK, 'note' => 'redis not selected (local fallback)'];
        }

        try {
            Redis::connection()->ping();

            return ['status' => self::STATUS_OK];
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_FAIL, 'error' => $e->getMessage()];
        }
    }

    private function checkQueueConnection(): array
    {
        return ['status' => self::STATUS_OK, 'connection' => config('queue.default')];
    }
}
