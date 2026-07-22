<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Storyboard shot-based pipeline overhaul (data-only; no schema change):
 *
 *  - Story Director: the director now DECIDES the cut list — a variable shot count within
 *    the project's bounds, each shot with its own duration + one concrete camera_movement
 *    (new shot_timing schema + prompts).
 *  - Scene Breakdown: slot camera_movement becomes BINDING per frame.
 *  - Frame Image: default flips to the EDIT-capable fal-ai/nano-banana/edit (chained on the
 *    previous frame), with google/gemini-3-pro-image as the look-setting first_frame_model
 *    param; sampler variance lowered (temp 0.3).
 *  - Clip: no fixed duration — each clip runs its frame's locked shot length within the new
 *    min/max_clip_seconds bounds; the prompt gains the {{camera}} line.
 *
 * NOTE: re-seeding OVERWRITES admin edits to these four steps' prompts/params (the
 * established targeted-seed pattern). Existing projects keep working: stored shot plans
 * re-normalize leniently (StoryboardTimingPlan::fromStored).
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = new StoryboardPipelineSeeder;

        $seeder->seedStoryDirectorStep();
        $seeder->seedSceneBreakdownStep();
        $seeder->seedFrameImageStep();
        $seeder->seedClipStep();
    }

    public function down(): void
    {
        // Config-only overwrite — nothing to restore (matches every prior seed migration).
    }
};
