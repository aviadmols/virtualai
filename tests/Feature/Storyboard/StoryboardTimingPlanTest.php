<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardTimingPlan;
use Tests\TestCase;

/**
 * The LOCKED shot plan (shot-based derivation): the DIRECTOR's variable cut list is accepted
 * within the project bounds, proportionally rescaled to the EXACT film duration, and always
 * contiguous; anything unusable falls back to uniform slices. fromStored() is the lenient
 * re-read for already-locked plans of any vintage (legacy time-sliced plans included).
 */
class StoryboardTimingPlanTest extends TestCase
{
    public function test_a_variable_count_proposal_within_bounds_is_locked_as_proposed(): void
    {
        $plan = StoryboardTimingPlan::normalize([
            ['shot_number' => 1, 'duration_seconds' => 3, 'camera_movement' => 'static wide'],
            ['shot_number' => 2, 'duration_seconds' => 3, 'camera_movement' => 'slow push-in'],
            ['shot_number' => 3, 'duration_seconds' => 4, 'camera_movement' => 'handheld follow'],
            ['shot_number' => 4, 'duration_seconds' => 5, 'camera_movement' => 'static close-up'],
        ], durationSeconds: 15, minShots: 3, maxShots: 15, fallbackShotSeconds: 3);

        $this->assertCount(4, $plan);
        $this->assertSame([3, 3, 4, 5], array_map(fn ($s) => $s['end_second'] - $s['start_second'], $plan));
        $this->assertSame(0, $plan[0]['start_second']);
        $this->assertSame(15, $plan[3]['end_second']);
        $this->assertSame('slow push-in', $plan[1]['camera_movement']);
    }

    public function test_a_shot_below_the_clip_floor_rejects_the_proposal(): void
    {
        // 2s < MIN_SHOT_SECONDS (3, the shortest renderable clip) → uniform fallback.
        $plan = StoryboardTimingPlan::normalize([
            ['shot_number' => 1, 'duration_seconds' => 2, 'camera_movement' => 'a'],
            ['shot_number' => 2, 'duration_seconds' => 13, 'camera_movement' => 'b'],
        ], durationSeconds: 15, minShots: 1, maxShots: 15, fallbackShotSeconds: 3);

        $this->assertCount(5, $plan);
        $this->assertSame(15, end($plan)['end_second']);
    }

    public function test_a_rescaled_shot_never_exceeds_the_per_shot_ceiling(): void
    {
        // The proposal's totals are off; naive proportional rescale would stretch the last
        // shot far past the 12s ceiling — the excess is redistributed instead.
        $plan = StoryboardTimingPlan::normalize([
            ['shot_number' => 1, 'duration_seconds' => 3, 'camera_movement' => 'a'],
            ['shot_number' => 2, 'duration_seconds' => 3, 'camera_movement' => 'b'],
            ['shot_number' => 3, 'duration_seconds' => 3, 'camera_movement' => 'c'],
            ['shot_number' => 4, 'duration_seconds' => 26, 'camera_movement' => 'd'],
        ], durationSeconds: 60, minShots: 1, maxShots: 20, fallbackShotSeconds: 6, maxShotSeconds: 12);

        $this->assertSame(60, end($plan)['end_second']);
        foreach ($plan as $slot) {
            $this->assertLessThanOrEqual(12, $slot['end_second'] - $slot['start_second']);
        }
        $this->assertContiguous($plan);
    }

    public function test_durations_are_proportionally_rescaled_to_the_exact_total(): void
    {
        // The model proposed 20s of shots for a 15s film — rescaled, still 3 shots, sum 15.
        $plan = StoryboardTimingPlan::normalize([
            ['shot_number' => 1, 'duration_seconds' => 10, 'camera_movement' => 'a'],
            ['shot_number' => 2, 'duration_seconds' => 5, 'camera_movement' => 'b'],
            ['shot_number' => 3, 'duration_seconds' => 5, 'camera_movement' => 'c'],
        ], durationSeconds: 15, minShots: 1, maxShots: 15, fallbackShotSeconds: 3);

        $this->assertCount(3, $plan);
        $this->assertSame(15, end($plan)['end_second']);
        $this->assertContiguous($plan);
    }

    public function test_a_count_outside_the_bounds_falls_back_to_uniform_slices(): void
    {
        // 2 shots when at least 3 are required → uniform ~3s slices, count within bounds.
        $plan = StoryboardTimingPlan::normalize([
            ['shot_number' => 1, 'duration_seconds' => 10, 'camera_movement' => 'a'],
            ['shot_number' => 2, 'duration_seconds' => 5, 'camera_movement' => 'b'],
        ], durationSeconds: 15, minShots: 3, maxShots: 15, fallbackShotSeconds: 3);

        $this->assertCount(5, $plan);
        $this->assertSame(15, end($plan)['end_second']);
        $this->assertContiguous($plan);
    }

    public function test_a_sub_second_shot_or_malformed_entry_falls_back(): void
    {
        foreach ([
            [['shot_number' => 1, 'duration_seconds' => 0, 'camera_movement' => 'a']],
            [['nonsense' => true]],
            'not-an-array',
            null,
        ] as $proposal) {
            $plan = StoryboardTimingPlan::normalize($proposal, 12, 2, 12, 3);

            $this->assertSame(4, count($plan));
            $this->assertSame(12, end($plan)['end_second']);
        }
    }

    public function test_from_stored_accepts_a_legacy_time_sliced_plan_of_any_count(): void
    {
        // A legacy 8-slot plan (old ceil(duration/interval) shape) re-normalizes untouched —
        // no bounds are applied to an ALREADY-LOCKED plan.
        $stored = [];
        for ($i = 0; $i < 8; $i++) {
            $stored[] = ['frame_number' => $i + 1, 'start_second' => $i * 3, 'end_second' => ($i + 1) * 3];
        }

        $plan = StoryboardTimingPlan::fromStored($stored, 24, 3);

        $this->assertCount(8, $plan);
        $this->assertSame(24, end($plan)['end_second']);
        $this->assertNull($plan[0]['camera_movement']);
    }

    public function test_from_stored_falls_back_to_uniform_on_a_malformed_plan(): void
    {
        $plan = StoryboardTimingPlan::fromStored('garbage', 9, 3);

        $this->assertCount(3, $plan);
        $this->assertSame(9, end($plan)['end_second']);
        $this->assertContiguous($plan);
    }

    /** @param array<int,array{frame_number:int,start_second:int,end_second:int}> $plan */
    private function assertContiguous(array $plan): void
    {
        $cursor = 0;
        foreach ($plan as $slot) {
            $this->assertSame($cursor, $slot['start_second']);
            $this->assertGreaterThan($slot['start_second'], $slot['end_second']);
            $cursor = $slot['end_second'];
        }
    }
}
