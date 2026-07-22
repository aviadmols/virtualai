<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * playground_runs.audio_path — the stored audio input for a talking-avatar run (Kling AI Avatar
 * takes an image + audio). Private media-disk path, signed at run time. Null for image/video runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playground_runs', function (Blueprint $table): void {
            $table->string('audio_path', 2048)->nullable()->after('input_paths');
        });
    }

    public function down(): void
    {
        Schema::table('playground_runs', function (Blueprint $table): void {
            $table->dropColumn('audio_path');
        });
    }
};
