<?php

namespace App\Support\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduler heartbeat. The scheduler service writes the key every minute; the
 * health surface reads its age. A web 200 does NOT prove the scheduler is alive —
 * this key is the single source of truth for "is the scheduler running".
 */
class Heartbeat
{
    // === CONSTANTS ===
    public const CACHE_KEY = 'scheduler.last_heartbeat_at';
    private const TTL_MINUTES = 30;

    // Age thresholds (seconds) -> health color.
    public const GREEN_MAX_AGE = 120;   // <= 2 min: alive
    public const YELLOW_MAX_AGE = 600;  // 2-10 min: lagging / deploying

    public const STATUS_GREEN = 'green';
    public const STATUS_YELLOW = 'yellow';
    public const STATUS_RED = 'red';

    /** Called by the per-minute scheduled command on the scheduler service. */
    public function beat(): void
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addMinutes(self::TTL_MINUTES));
    }

    public function lastBeatAt(): ?Carbon
    {
        $value = Cache::get(self::CACHE_KEY);

        return $value ? Carbon::parse($value) : null;
    }

    public function ageSeconds(): ?int
    {
        $at = $this->lastBeatAt();

        return $at?->diffInSeconds(now());
    }

    /** Map the heartbeat age to a green/yellow/red status. */
    public function status(): string
    {
        $age = $this->ageSeconds();

        if ($age === null) {
            return self::STATUS_RED;
        }

        return match (true) {
            $age <= self::GREEN_MAX_AGE => self::STATUS_GREEN,
            $age <= self::YELLOW_MAX_AGE => self::STATUS_YELLOW,
            default => self::STATUS_RED,
        };
    }
}
