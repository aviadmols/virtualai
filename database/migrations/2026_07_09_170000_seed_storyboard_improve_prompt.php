<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed the on-demand "improve prompt" storyboard operation onto existing installs. Seeds ONLY that
 * one operation (not the whole pipeline) so admin-edited prompts on the other steps are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new StoryboardPipelineSeeder)->seedImprovePromptStep();
    }

    public function down(): void
    {
        // Leave the seeded operation in place (idempotent seed; nothing tenant-owned to reverse).
    }
};
