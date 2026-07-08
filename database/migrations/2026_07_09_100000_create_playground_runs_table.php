<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * playground_runs — the Super-Admin Model Playground history (a GLOBAL, non-tenant table).
 *
 * Each row is ONE admin test of an image/video model: the prompt + input image paths, the
 * resulting media, and the measured render time + cost. NOT tenant-owned (no account_id; it is
 * on GlobalModels::ALLOW_LIST) and NOT on the money path — a playground run never charges anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playground_runs', function (Blueprint $table) {
            $table->id();
            // The admin who ran it (nullable so a deleted admin doesn't erase the history).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 8)->index();          // image | video
            $table->string('provider', 16);               // openrouter | byteplus | xai
            $table->string('model_id');
            $table->text('prompt');
            $table->json('input_paths')->nullable();       // media-disk paths of the uploaded inputs

            $table->string('status', 12)->default('queued')->index(); // queued | running | succeeded | failed
            $table->string('provider_task_id')->nullable();  // the async video task id (video only)
            $table->string('result_path')->nullable();       // media-disk path of the result
            $table->string('result_mime', 32)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();     // measured render time
            $table->bigInteger('cost_micro_usd')->nullable();       // computed/displayed cost (never charged)
            $table->string('cost_source', 16)->nullable();          // inline | flat_rate | unavailable
            $table->bigInteger('price_hint_micro_usd')->nullable(); // the flat-rate per-run price used
            $table->unsignedBigInteger('tokens_used')->nullable();  // video usage.total_tokens (info only)

            $table->unsignedSmallInteger('poll_attempts')->default(0); // async video poll counter
            $table->text('error')->nullable();
            $table->json('meta')->nullable();               // ratio / resolution / duration / region base_url

            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_runs');
    }
};
