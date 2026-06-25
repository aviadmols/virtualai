<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * credit_ledger — THE MONEY TRUTH. Append-only, account-scoped (credits are shared
 * across an account's sites). Every credit movement is one immutable row:
 * grant | purchase | charge | refund | adjustment. There is no UPDATE/DELETE path:
 * a correction is a NEW row (a refund reverses a charge; an adjustment is admin ±).
 *
 * No charge without a row here, and the row is written in the same transaction that
 * mutates accounts.balance_micro_usd, so the snapshot (balance_after_micro_usd) and
 * the live balance can never drift.
 *
 * amount_micro_usd is SIGNED integer micro-USD of SELLING value (never a float):
 *   grant / purchase / refund -> positive; charge -> negative; adjustment -> either.
 *
 * idempotency_key is UNIQUE so a duplicate charge is impossible at the DB level —
 * the deterministic generation/refund/grant key collides on a second write.
 * There is intentionally NO updated_at: the row is created once and never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary. account_id NOT NULL + FK + index.
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            // grant | purchase | charge | refund | adjustment.
            $table->string('type', 16);

            // SIGNED micro-USD of selling value. charge is negative; grant/purchase/
            // refund positive; adjustment either sign. Never a float.
            $table->bigInteger('amount_micro_usd');

            // The account balance AFTER this row (fast reads + reconciliation).
            $table->bigInteger('balance_after_micro_usd');

            // The deterministic idempotency key (ARCHITECTURE.md). UNIQUE: a second
            // write for the same key (a double-clicked charge, a retried refund) is
            // rejected by the DB — the four-layer charge defense's hard floor.
            //
            // N1: the unique index is GLOBAL (not scoped to account_id), and that is
            // SAFE only because every key built by IdempotencyKey embeds account_id as
            // its first segment after the prefix (generation:{account}:..., charge keys,
            // refund:{account}:..., grant:{account}:..., purchase:{account}:...). Two
            // different accounts can therefore never mint the same key, so a global
            // unique cannot cross-collide. If a future key omits account_id, this index
            // must become composite (account_id, idempotency_key).
            $table->string('idempotency_key');

            // Polymorphic-ish reference to what produced the row (a generation for a
            // charge/refund, a purchase for a purchase). Nullable for grant/adjustment.
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // The real OpenRouter cost behind a charge (for margin reporting). Null
            // for non-charge rows. Integer micro-USD.
            $table->bigInteger('actual_cost_micro_usd')->nullable();

            // Structured trace (description, multiplier, provider ref, admin actor).
            $table->json('meta')->nullable();

            // Append-only: created once, never edited. No updated_at by design.
            $table->timestamp('created_at')->useCurrent();

            // A duplicate charge/refund/grant is impossible at the DB level.
            $table->unique('idempotency_key');

            // Hot paths: a per-account ledger read (timeline), a per-account
            // type filter (sum of charges), and a charge/refund lookup by reference.
            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'type', 'created_at']);
            $table->index(['account_id', 'reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
