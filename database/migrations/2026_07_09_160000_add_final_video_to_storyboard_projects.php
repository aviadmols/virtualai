<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project-level COMBINED video: all frames stitched (ffmpeg) into one MP4. Path + status +
 * meta (error / duration / resolution) live on the project — the builder shows the final clip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storyboard_projects', function (Blueprint $table): void {
            $table->string('final_video_path')->nullable()->after('pipeline');
            $table->string('final_video_status')->nullable()->after('final_video_path');
            $table->json('final_video_meta')->nullable()->after('final_video_status');
        });
    }

    public function down(): void
    {
        Schema::table('storyboard_projects', function (Blueprint $table): void {
            $table->dropColumn(['final_video_path', 'final_video_status', 'final_video_meta']);
        });
    }
};
