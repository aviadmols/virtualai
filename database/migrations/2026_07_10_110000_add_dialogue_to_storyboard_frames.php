<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-frame spoken DIALOGUE: what the character says in this frame. Written by the admin in the
 * Builder (limited to what fits the frame's seconds at speech pace) and carried into the video
 * generation (per-frame clips + the one-video prompt) for lip-synced speech.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storyboard_frames', static function (Blueprint $table): void {
            $table->text('dialogue')->nullable()->after('text_overlay');
        });
    }

    public function down(): void
    {
        Schema::table('storyboard_frames', static function (Blueprint $table): void {
            $table->dropColumn('dialogue');
        });
    }
};
