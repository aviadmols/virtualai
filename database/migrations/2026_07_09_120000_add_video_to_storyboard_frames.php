<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storyboard_frames gains the per-frame VIDEO clip fields — Phase 5. Each ready frame image can be
 * animated into a short clip (image-to-video via Seedance): the async task id, the stored mp4 path,
 * its status + render time, and the motion prompt. Null video_status = no clip yet. Not tenant, no
 * charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storyboard_frames', function (Blueprint $table) {
            $table->string('motion_prompt')->nullable()->after('negative_prompt');
            $table->string('video_path')->nullable()->after('image_path');
            $table->string('video_status', 16)->nullable()->after('video_path'); // generating|ready|failed
            $table->string('video_task_id')->nullable()->after('video_status');
            $table->unsignedInteger('video_duration_ms')->nullable()->after('video_task_id');
            $table->unsignedSmallInteger('video_poll_attempts')->default(0)->after('video_duration_ms');
            $table->json('video_meta')->nullable()->after('video_poll_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('storyboard_frames', function (Blueprint $table) {
            $table->dropColumn(['motion_prompt', 'video_path', 'video_status', 'video_task_id', 'video_duration_ms', 'video_poll_attempts', 'video_meta']);
        });
    }
};
