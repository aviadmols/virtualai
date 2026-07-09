<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Storyboard frame images move to fal.ai Krea 2 Turbo (fal-ai/krea-2/turbo) as the default; the
 * Gemini image models stay catalogued as selectable options, and the clip step gains NON-default
 * fal video options (Kling / Veo). Targeted re-seed of ONLY the frame-image + clip steps — the
 * five text-planning steps (and their admin edits) are untouched.
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
        // Config-only overwrite; nothing tenant-owned to reverse (matches prior seed migrations).
    }
};
