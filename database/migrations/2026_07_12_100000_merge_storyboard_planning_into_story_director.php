<?php

use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge the four storyboard planning steps (read_idea, genre, characters, visual_bible) into the
 * ONE Story Director call, and re-seed the Scene Breakdown for the locked-plan contract (locked
 * shot timing, scene_prompt-only frames — the composer assembles the final prompts in code).
 * Halves the planning cost/latency and removes the between-step drift (timing contradictions,
 * re-worded wardrobe). The retired operations + their prompts + model catalog rows are removed;
 * historic storyboard_step_runs rows keep their step_key for the audit trail.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const RETIRED_KEYS = [
        'storyboard_read_idea',
        'storyboard_genre',
        'storyboard_characters',
        'storyboard_visual_bible',
    ];

    public function up(): void
    {
        $seeder = new StoryboardPipelineSeeder;
        $seeder->seedStoryDirectorStep();
        $seeder->seedSceneBreakdownStep();

        DB::table('ai_operations')->whereIn('operation_key', self::RETIRED_KEYS)->delete();
        DB::table('ai_models')->whereIn('operation_key', self::RETIRED_KEYS)->delete();
        DB::table('prompts')->whereIn('operation_key', self::RETIRED_KEYS)->delete();
    }

    public function down(): void
    {
        // Config-only change; the retired steps are re-creatable only from an older seeder,
        // so there is nothing safe to restore here (matches prior seed migrations).
    }
};
