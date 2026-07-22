<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Storyboard V2 — the Kling-native engine (data-only; no schema change):
 *
 *  - Frame images move to Kling's native API (kling-v3, fallback kling-v2-1): the Gemini
 *    image tier refuses too many people/child generations, killing runs. Consistency is
 *    carried by the chain — each frame is generated FROM the previous frame's image as
 *    Kling's single reference (params gain image_reference=subject).
 *  - Clips move to Kling native i2v (kling-v2-5-turbo @1080p → `pro` mode): each clip runs
 *    its shot's locked length and carries the NEXT shot's opening frame as image_tail, so
 *    consecutive clips CONNECT — every cut lands where the next shot begins.
 *
 * NOTE: re-seeding OVERWRITES admin edits to these two steps' prompts/params (the
 * established targeted-seed pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = new StoryboardPipelineSeeder;

        $seeder->seedFrameImageStep();
        $seeder->seedClipStep();
    }

    public function down(): void
    {
        // Config-only overwrite — nothing to restore (matches every prior seed migration).
    }
};
