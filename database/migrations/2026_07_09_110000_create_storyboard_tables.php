<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storyboard — the admin AI pre-production builder (a GLOBAL, non-tenant module).
 *
 * A project turns a story idea + tagged reference images into a storyboard: one frame per N
 * seconds, each frame an editable image with its own prompt + versions. Every table here is
 * platform/admin-owned (created_by, no account_id) and on GlobalModels::ALLOW_LIST — NOT tenant
 * data, and NOT on the money path. The per-step AI config lives in ai_operations (the existing
 * DB-managed pipeline engine); step runs are logged in storyboard_step_runs for the progress UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storyboard_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('story_idea');
            $table->string('genre')->nullable();
            $table->unsignedInteger('duration_seconds')->default(15);
            $table->unsignedSmallInteger('frame_interval_seconds')->default(3);
            $table->string('aspect_ratio', 12)->default('16:9');
            $table->string('resolution', 12)->nullable();
            $table->string('platform_target')->nullable();      // TikTok / Instagram / YouTube / Ads
            $table->text('visual_style')->nullable();
            $table->string('status', 16)->default('draft')->index(); // draft|running|ready|failed
            // Intermediate pipeline outputs (clean story, genre profile, characters, visual bible).
            $table->json('pipeline')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('storyboard_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('storyboard_projects')->cascadeOnDelete();
            $table->string('tag');                               // e.g. main_character (used as @main_character)
            $table->string('type', 24)->default('reference');    // character|location|product|logo|style|outfit|prop
            $table->string('file_path')->nullable();             // media-disk path of the uploaded reference
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('reference_strength')->default(70); // 0-100 influence
            $table->boolean('keep_exact')->default(false);       // 1:1 vs inspiration only
            $table->boolean('is_locked')->default(false);        // pinned for the whole project
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'tag']);
        });

        Schema::create('storyboard_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('storyboard_projects')->cascadeOnDelete();
            $table->unsignedInteger('frame_number');
            $table->unsignedInteger('start_second');
            $table->unsignedInteger('end_second');
            $table->text('description')->nullable();
            $table->string('camera_angle')->nullable();
            $table->string('composition')->nullable();
            $table->text('action')->nullable();
            $table->json('characters')->nullable();              // referenced character tags
            $table->json('reference_tags')->nullable();          // referenced asset tags
            $table->string('text_overlay')->nullable();
            $table->text('image_prompt')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->string('image_path')->nullable();            // the selected version's media path
            $table->string('status', 16)->default('pending')->index(); // pending|generating|ready|failed
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'frame_number']);
        });

        Schema::create('storyboard_frame_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frame_id')->constrained('storyboard_frames')->cascadeOnDelete();
            $table->string('image_path')->nullable();
            $table->text('prompt');
            $table->text('negative_prompt')->nullable();
            $table->json('reference_assets')->nullable();
            $table->text('edit_instruction')->nullable();
            $table->string('provider', 16)->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->boolean('is_selected')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['frame_id', 'version_number']);
        });

        Schema::create('storyboard_step_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('storyboard_projects')->cascadeOnDelete();
            $table->string('step_key');                          // an ai_operations operation_key
            $table->string('status', 16)->default('pending');    // pending|running|succeeded|failed
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('provider', 16)->nullable();
            $table->string('model')->nullable();
            $table->bigInteger('cost_micro_usd')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storyboard_step_runs');
        Schema::dropIfExists('storyboard_frame_versions');
        Schema::dropIfExists('storyboard_frames');
        Schema::dropIfExists('storyboard_assets');
        Schema::dropIfExists('storyboard_projects');
    }
};
