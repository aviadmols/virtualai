<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activity_events — the human-facing Timeline. Append-only, account-scoped.
 *
 * The trace of every scan, generation, gate decision, lead event, ledger movement,
 * state transition and admin action — cross-linked to its subject by a polymorphic
 * (subject_type, subject_id). The full timeline view is Phase 6; Phase 5a writes
 * the ledger/gate traces (grant, charge, release, credit/lead-gate blocks) now.
 *
 * The recorder SWALLOWS its own exceptions: a failed trace write must NEVER block
 * or roll back the money path. There is no updated_at (events are immutable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary.
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            // Sub-scope, nullable for account-level events (a grant has no site).
            $table->unsignedBigInteger('site_id')->nullable();

            // Who caused the event: system | merchant | end_user | webhook.
            $table->string('actor', 16)->default('system');

            // The typed event kind (a stable taxonomy, e.g. credit_charged,
            // credit_gate_blocked, lead_gate_blocked, opening_grant).
            $table->string('kind', 48);

            // Polymorphic subject the event is about (a Generation, an EndUser, the
            // Account). Nullable so an account-level event needs no subject.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Structured detail bag (amounts, reasons, before/after).
            $table->json('details')->nullable();

            // Append-only: created once, never edited.
            $table->timestamp('created_at')->useCurrent();

            // Hot path: a per-account timeline, and a per-subject history.
            $table->index(['account_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
