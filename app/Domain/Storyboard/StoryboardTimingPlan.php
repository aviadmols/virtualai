<?php

namespace App\Domain\Storyboard;

/**
 * StoryboardTimingPlan — the LOCKED per-frame shot timing.
 *
 * The Story Director proposes the pacing ONCE (shot_timing); this normalizer turns it into a
 * contiguous, gap-free plan covering 0..duration, or falls back to uniform interval slices when
 * the proposal is unusable. The scene breakdown receives the plan as read-only data and the
 * materialised frames get their start/end FROM THE PLAN — never from the breakdown's own output —
 * so the pacing decided at planning time can never drift.
 */
final class StoryboardTimingPlan
{
    // === CONSTANTS ===
    private const MIN_SHOT_SECONDS = 1;

    /**
     * Normalize a proposed timing into exactly $frameCount contiguous [start,end) slots summing
     * to $durationSeconds. The proposal's per-shot DURATIONS are kept when they are usable
     * (right count, each >= 1s); a small arithmetic slip is absorbed by the last shot. Anything
     * else falls back to uniform $intervalSeconds slices.
     *
     * @param  mixed  $proposed  the Story Director's shot_timing (or a stored plan)
     * @return array<int,array{frame_number:int,start_second:int,end_second:int}>
     */
    public static function normalize(mixed $proposed, int $durationSeconds, int $intervalSeconds, int $frameCount): array
    {
        $durations = self::proposedDurations($proposed, $frameCount, $durationSeconds);

        if ($durations === null) {
            return self::uniform($durationSeconds, $intervalSeconds, $frameCount);
        }

        return self::contiguous($durations);
    }

    /**
     * The proposal's per-shot durations when usable, else null. Usable = exactly $frameCount
     * entries, every duration >= 1s, and the total matches $durationSeconds after letting the
     * LAST shot absorb any small remainder (still >= 1s).
     *
     * @return array<int,int>|null
     */
    private static function proposedDurations(mixed $proposed, int $frameCount, int $durationSeconds): ?array
    {
        if (! is_array($proposed) || count($proposed) !== $frameCount) {
            return null;
        }

        $durations = [];

        foreach (array_values($proposed) as $slot) {
            if (! is_array($slot) || ! is_numeric($slot['start_second'] ?? null) || ! is_numeric($slot['end_second'] ?? null)) {
                return null;
            }

            $seconds = (int) $slot['end_second'] - (int) $slot['start_second'];

            if ($seconds < self::MIN_SHOT_SECONDS) {
                return null;
            }

            $durations[] = $seconds;
        }

        $last = count($durations) - 1;
        $durations[$last] += $durationSeconds - array_sum($durations);

        return $durations[$last] >= self::MIN_SHOT_SECONDS ? $durations : null;
    }

    /** @return array<int,array{frame_number:int,start_second:int,end_second:int}> */
    private static function uniform(int $durationSeconds, int $intervalSeconds, int $frameCount): array
    {
        $interval = max(self::MIN_SHOT_SECONDS, $intervalSeconds);
        $durations = [];

        for ($i = 0; $i < $frameCount; $i++) {
            $start = $i * $interval;
            $durations[] = max(self::MIN_SHOT_SECONDS, min(($i + 1) * $interval, $durationSeconds) - $start);
        }

        return self::contiguous($durations);
    }

    /**
     * Chain durations into contiguous slots from 0 — by construction there can be no gap and no
     * overlap, whatever the proposal's own start/end arithmetic said.
     *
     * @param  array<int,int>  $durations
     * @return array<int,array{frame_number:int,start_second:int,end_second:int}>
     */
    private static function contiguous(array $durations): array
    {
        $plan = [];
        $cursor = 0;

        foreach (array_values($durations) as $i => $seconds) {
            $plan[] = [
                'frame_number' => $i + 1,
                'start_second' => $cursor,
                'end_second' => $cursor + $seconds,
            ];
            $cursor += $seconds;
        }

        return $plan;
    }
}
