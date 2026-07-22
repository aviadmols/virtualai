<?php

namespace App\Domain\Storyboard;

use App\Models\StoryboardProject;

/**
 * StoryboardTimingPlan — the LOCKED shot plan (shot-based derivation).
 *
 * The Story Director proposes the CUT LIST once: a variable number of shots, each one
 * continuous camera setup/movement with its own duration. This normalizer validates the
 * proposal against the project's shot bounds, proportionally rescales the durations so they
 * sum EXACTLY to the film's duration (largest-remainder integer rounding; no shot below 1s),
 * and locks the result. The scene breakdown receives the plan read-only and the materialised
 * frames get their count AND timing FROM THE PLAN — never from the breakdown's own output.
 *
 * fromStored() is the lenient re-read for an ALREADY-LOCKED plan (any count, incl. plans
 * written by the legacy time-slicing pipeline) — backwards compatible by design.
 */
final class StoryboardTimingPlan
{
    // === CONSTANTS ===
    private const MIN_SHOT_SECONDS = StoryboardProject::MIN_SHOT_SECONDS;

    // Slot keys (the stored plan shape; camera_movement is carried for downstream fallbacks).
    private const KEY_FRAME = 'frame_number';

    private const KEY_START = 'start_second';

    private const KEY_END = 'end_second';

    private const KEY_CAMERA = 'camera_movement';

    /**
     * Normalize the DIRECTOR's proposal into contiguous [start,end) slots summing exactly to
     * $durationSeconds. Usable = every entry parses, every shot >= 1s, and the shot COUNT sits
     * within [$minShots, $maxShots]; durations are then proportionally rescaled to the exact
     * total. Anything else falls back to uniform slices of ~$fallbackShotSeconds.
     *
     * @param  mixed  $proposed  the Story Director's shot_timing
     * @return array<int,array{frame_number:int,start_second:int,end_second:int,camera_movement:?string}>
     */
    public static function normalize(mixed $proposed, int $durationSeconds, int $minShots, int $maxShots, int $fallbackShotSeconds, ?int $maxShotSeconds = null): array
    {
        $shots = self::proposedShots($proposed);

        if ($shots === null || count($shots) < $minShots || count($shots) > $maxShots || ! self::fits($shots, $durationSeconds)) {
            return self::uniform($durationSeconds, $fallbackShotSeconds, $minShots, $maxShots);
        }

        $shots = self::rescale($shots, $durationSeconds);

        // A proposal whose total was off gets stretched proportionally, which can push a shot
        // past the ceiling the director was told to obey — and an over-long shot is silently
        // truncated by the clip model later, so the film would run short. Redistribute instead;
        // when the ceiling cannot hold the duration at this count, fall back to uniform slices.
        if ($maxShotSeconds !== null && self::exceedsCeiling($shots, $maxShotSeconds)) {
            $shots = count($shots) * $maxShotSeconds >= $durationSeconds
                ? self::capShots($shots, $maxShotSeconds)
                : self::proposedShots(self::uniform($durationSeconds, $maxShotSeconds, $minShots, $maxShots)) ?? $shots;
        }

        return self::contiguous($shots);
    }

    /**
     * Re-normalize an ALREADY-LOCKED plan (stored under pipeline.shot_timing). Lenient: any
     * count is accepted (legacy time-sliced plans included); only a malformed/empty plan falls
     * back to uniform slices. Rescale still guarantees the exact total duration.
     *
     * @return array<int,array{frame_number:int,start_second:int,end_second:int,camera_movement:?string}>
     */
    public static function fromStored(mixed $stored, int $durationSeconds, int $fallbackShotSeconds): array
    {
        $shots = self::proposedShots($stored);

        if ($shots === null || $shots === [] || ! self::fits($shots, $durationSeconds)) {
            return self::uniform($durationSeconds, $fallbackShotSeconds, null, null);
        }

        return self::contiguous(self::rescale($shots, $durationSeconds));
    }

    /**
     * Parse a proposal into [{seconds, camera}] — accepts BOTH the new director shape
     * ({shot_number, duration_seconds, camera_movement}) and the legacy/stored slot shape
     * ({frame_number, start_second, end_second}). Null when any entry is malformed or a shot
     * is shorter than 1s.
     *
     * @return array<int,array{seconds:int,camera:?string}>|null
     */
    private static function proposedShots(mixed $proposed): ?array
    {
        if (! is_array($proposed) || $proposed === []) {
            return null;
        }

        $shots = [];

        foreach (array_values($proposed) as $slot) {
            if (! is_array($slot)) {
                return null;
            }

            if (is_numeric($slot['duration_seconds'] ?? null)) {
                $seconds = (int) $slot['duration_seconds'];
            } elseif (is_numeric($slot[self::KEY_START] ?? null) && is_numeric($slot[self::KEY_END] ?? null)) {
                $seconds = (int) $slot[self::KEY_END] - (int) $slot[self::KEY_START];
            } else {
                return null;
            }

            if ($seconds < self::MIN_SHOT_SECONDS) {
                return null;
            }

            $camera = $slot[self::KEY_CAMERA] ?? null;

            $shots[] = [
                'seconds' => $seconds,
                'camera' => is_string($camera) && trim($camera) !== '' ? trim($camera) : null,
            ];
        }

        return $shots;
    }

    /**
     * Proportionally rescale shot durations to sum EXACTLY to $durationSeconds: floor the
     * exact shares, hand the remainder to the largest fractional parts, then lift any sub-1s
     * shot to 1s by stealing from the currently-longest shot. Feasible whenever
     * count <= duration (guarded by the callers).
     *
     * @param  array<int,array{seconds:int,camera:?string}>  $shots
     * @return array<int,array{seconds:int,camera:?string}>
     */
    private static function rescale(array $shots, int $durationSeconds): array
    {
        $sum = array_sum(array_column($shots, 'seconds'));

        if ($sum === $durationSeconds) {
            return $shots;
        }

        // Floor the proportional shares and remember each remainder.
        $fractions = [];
        foreach ($shots as $i => $shot) {
            $exact = $shot['seconds'] * $durationSeconds / $sum;
            $shots[$i]['seconds'] = (int) floor($exact);
            $fractions[$i] = $exact - floor($exact);
        }

        // Largest-remainder: distribute the missing seconds to the biggest fractions first.
        arsort($fractions);
        $missing = $durationSeconds - array_sum(array_column($shots, 'seconds'));
        foreach (array_keys($fractions) as $i) {
            if ($missing <= 0) {
                break;
            }
            $shots[$i]['seconds']++;
            $missing--;
        }

        // No shot may drop under 1s — steal from the longest until every shot is legal.
        foreach ($shots as $i => $shot) {
            while ($shots[$i]['seconds'] < self::MIN_SHOT_SECONDS) {
                $longest = array_search(max(array_column($shots, 'seconds')), array_column($shots, 'seconds'), true);
                $shots[$longest]['seconds']--;
                $shots[$i]['seconds']++;
            }
        }

        return $shots;
    }

    /**
     * Can this many shots legally cover the duration? Every shot needs MIN_SHOT_SECONDS, so
     * a count that cannot be satisfied is rejected BEFORE rescale() — otherwise its
     * lift-the-short-shots loop would have no donor left and could not converge.
     *
     * @param  array<int,array{seconds:int,camera:?string}>  $shots
     */
    private static function fits(array $shots, int $durationSeconds): bool
    {
        return count($shots) * self::MIN_SHOT_SECONDS <= $durationSeconds;
    }

    /**
     * True when any shot runs longer than the per-shot ceiling.
     *
     * @param  array<int,array{seconds:int,camera:?string}>  $shots
     */
    private static function exceedsCeiling(array $shots, int $maxShotSeconds): bool
    {
        return max(array_column($shots, 'seconds')) > $maxShotSeconds;
    }

    /**
     * Trim every over-long shot to the ceiling and hand the reclaimed seconds to the shots
     * that still have headroom (shortest first), so the total stays EXACTLY the duration.
     * Feasible only when count * ceiling >= duration — the caller checks that.
     *
     * @param  array<int,array{seconds:int,camera:?string}>  $shots
     * @return array<int,array{seconds:int,camera:?string}>
     */
    private static function capShots(array $shots, int $maxShotSeconds): array
    {
        $spare = 0;

        foreach ($shots as $i => $shot) {
            if ($shot['seconds'] > $maxShotSeconds) {
                $spare += $shot['seconds'] - $maxShotSeconds;
                $shots[$i]['seconds'] = $maxShotSeconds;
            }
        }

        while ($spare > 0) {
            $lengths = array_column($shots, 'seconds');
            asort($lengths);
            $moved = false;

            foreach (array_keys($lengths) as $i) {
                if ($spare > 0 && $shots[$i]['seconds'] < $maxShotSeconds) {
                    $shots[$i]['seconds']++;
                    $spare--;
                    $moved = true;
                }
            }

            if (! $moved) {
                break; // no headroom left anywhere (guarded by the caller's feasibility check)
            }
        }

        return $shots;
    }

    /**
     * Uniform fallback: even slices of ~$shotSeconds, count clamped into the given bounds
     * (null bounds = unclamped, the lenient stored-plan path).
     *
     * @return array<int,array{frame_number:int,start_second:int,end_second:int,camera_movement:?string}>
     */
    private static function uniform(int $durationSeconds, int $shotSeconds, ?int $minShots, ?int $maxShots): array
    {
        $duration = max(1, $durationSeconds);
        $shotLength = max(self::MIN_SHOT_SECONDS, $shotSeconds);

        $count = (int) max(1, (int) ceil($duration / $shotLength));
        $count = $minShots !== null ? max($count, $minShots) : $count;
        $count = $maxShots !== null ? min($count, $maxShots) : $count;
        // Never more shots than the duration can legally hold at the per-shot floor.
        $count = (int) max(1, min($count, intdiv($duration, self::MIN_SHOT_SECONDS)));

        // Even integer split: the first (duration % count) shots run one second longer.
        $base = intdiv($duration, $count);
        $extra = $duration % $count;

        $shots = [];
        for ($i = 0; $i < $count; $i++) {
            $shots[] = ['seconds' => $base + ($i < $extra ? 1 : 0), 'camera' => null];
        }

        return self::contiguous($shots);
    }

    /**
     * Chain shots into contiguous slots from 0 — by construction no gap and no overlap,
     * whatever the proposal's own start/end arithmetic said.
     *
     * @param  array<int,array{seconds:int,camera:?string}>  $shots
     * @return array<int,array{frame_number:int,start_second:int,end_second:int,camera_movement:?string}>
     */
    private static function contiguous(array $shots): array
    {
        $plan = [];
        $cursor = 0;

        foreach (array_values($shots) as $i => $shot) {
            $plan[] = [
                self::KEY_FRAME => $i + 1,
                self::KEY_START => $cursor,
                self::KEY_END => $cursor + $shot['seconds'],
                self::KEY_CAMERA => $shot['camera'],
            ];
            $cursor += $shot['seconds'];
        }

        return $plan;
    }
}
