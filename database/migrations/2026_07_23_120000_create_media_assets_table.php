<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform media assets — Super-Admin uploads (fonts / images / video / audio /
 * files) served at stable public URLs. GLOBAL table: no account_id on purpose
 * (GlobalModels::ALLOW_LIST).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 20)->index();
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
