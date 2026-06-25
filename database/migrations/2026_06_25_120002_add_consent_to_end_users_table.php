<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two SEPARATE, independent consents on end_users (GDPR — they never collapse):
 *
 *  - photo_consent_at: the use-my-photo consent (explicit, per the try-on). Null until
 *    the shopper agrees; no try-on without it. A timestamp, not a flag, so we record
 *    WHEN it was given (the provable basis for processing the uploaded photo).
 *
 *  - marketing_consent: a SEPARATE opt-in to receive merchant marketing. DEFAULTS FALSE
 *    (OFF). It is never pre-checked, never implied by the photo consent. A pre-checked
 *    or default-on marketing box is a GDPR violation + a launch blocker — the column
 *    default is the storage-level guarantee that it starts OFF.
 *  - marketing_consent_at: WHEN marketing opt-in was given (null while off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('end_users', function (Blueprint $table) {
            // Use-my-photo consent timestamp (null until the shopper explicitly agrees).
            $table->timestamp('photo_consent_at')->nullable()->after('phone');

            // Marketing opt-in — DEFAULTS OFF. Separate field, never implied by photo consent.
            $table->boolean('marketing_consent')->default(false)->after('photo_consent_at');
            $table->timestamp('marketing_consent_at')->nullable()->after('marketing_consent');
        });
    }

    public function down(): void
    {
        Schema::table('end_users', function (Blueprint $table) {
            $table->dropColumn(['photo_consent_at', 'marketing_consent', 'marketing_consent_at']);
        });
    }
};
