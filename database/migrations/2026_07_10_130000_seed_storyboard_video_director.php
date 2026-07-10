<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed the storyboard_video_director operation: the DIRECTOR pass that composes the final
 * one-call video prompt (timed shot list + continuity) from the frame images + storyboard data.
 * TARGETED on purpose — a full pipeline re-seed would revert the admin's clip-model choice and
 * wipe prompt edits (matches the improve-prompt seed-migration precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new StoryboardPipelineSeeder)->seedVideoDirectorStep();
    }

    public function down(): void
    {
        // Config-only addition; nothing tenant-owned to reverse (matches prior seed migrations).
    }
};
