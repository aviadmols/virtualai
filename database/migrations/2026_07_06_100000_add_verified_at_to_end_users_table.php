<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-Club membership (Phase 2a). A club member is a VERIFIED EndUser — we
 * reuse the existing lead row rather than a separate member model.
 *
 * verified_at: null until the shopper proves ownership of their email via the
 * one-time code. A timestamp (not a flag) so we record WHEN membership was
 * established. isClubMember() === (verified_at !== null). The verification itself
 * is an explicit marketing opt-in, so the existing marketing_consent columns
 * (already default OFF, GDPR) carry the consent — no new consent column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('end_users', function (Blueprint $table): void {
            // Club-membership stamp — null until the email one-time code verifies.
            $table->timestamp('verified_at')->nullable()->after('registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('end_users', function (Blueprint $table): void {
            $table->dropColumn('verified_at');
        });
    }
};
