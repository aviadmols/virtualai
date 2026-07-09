<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storyboard_frames gains per-frame cost columns so the builder can total the REAL cost of a
 * storyboard: the image generation cost (OpenRouter inline, when available) + the video clip cost
 * (the flat-rate per-clip price). Combined with storyboard_step_runs.cost_micro_usd this gives the
 * whole project cost. Display only — never charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storyboard_frames', function (Blueprint $table) {
            $table->bigInteger('image_cost_micro_usd')->nullable()->after('video_duration_ms');
            $table->bigInteger('video_cost_micro_usd')->nullable()->after('image_cost_micro_usd');
        });
    }

    public function down(): void
    {
        Schema::table('storyboard_frames', function (Blueprint $table) {
            $table->dropColumn(['image_cost_micro_usd', 'video_cost_micro_usd']);
        });
    }
};
