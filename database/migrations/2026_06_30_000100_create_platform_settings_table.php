<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_settings — the global control-plane key/value store (Super-Admin only).
 *
 * Holds platform-wide config a super-admin manages from the UI without a redeploy:
 * the OpenRouter API key, the PayPlus payment credentials, etc. Secret values are
 * stored ENCRYPTED at rest (EncryptedString cast on the model) and are never sent
 * back to the browser. NOT tenant-scoped — it is a global allow-list model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            // Ciphertext (EncryptedString cast). Nullable: an unset secret is NULL.
            $table->text('value')->nullable();
            // Whether the value is a secret (masked + write-only in the UI).
            $table->boolean('is_secret')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
