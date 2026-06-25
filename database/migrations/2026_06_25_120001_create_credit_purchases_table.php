<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * credit_purchases — the PLATFORM-REVENUE rail (the merchant pays the platform to top
 * up credits). This is the INBOUND PAYMENT record, kept strictly SEPARATE from
 * credit_ledger (the merchant's spend/grant truth). They are linked 1:1 by ledger_id,
 * written in one transaction, and never merged.
 *
 * Tenant-owned: account_id NOT NULL + BelongsToAccount (one account's purchases). The
 * idempotency_key (purchase:{account}:{provider}:{provider_ref}) is UNIQUE — it is the
 * webhook's dedupe wall, so a retried/forged-duplicate webhook can credit at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();

            // Tenancy — isolation boundary (carried explicitly, never inferred).
            $table->foreignId('account_id')
                ->constrained('accounts')->cascadeOnDelete();

            // Which CreditPaymentProvider handled it (payplus for v1).
            $table->string('provider', 32);

            // The provider's payment-page / transaction id. Part of the idempotency key.
            $table->string('provider_ref');

            // The dollar amount the merchant paid (snapshot, major units).
            $table->decimal('amount_usd', 12, 2);

            // The selling-value micro-USD this purchase grants (face value; markup is on
            // spend, not on purchase): credits_micro_usd == amount_usd * 1_000_000.
            $table->bigInteger('credits_micro_usd');

            // Charge currency (USD default; ILS path for PayPlus is the Q1-open decision).
            $table->string('currency', 3)->default('USD');

            // Mirrors the provider: pending -> paid | failed | refunded.
            $table->string('status', 16)->default('pending');

            // FK -> the credit_ledger `purchase` row written on `paid`. NULL until the
            // webhook fires; the 1:1 link proving the purchase reached the ledger ONCE.
            $table->foreignId('ledger_id')->nullable()
                ->constrained('credit_ledger')->nullOnDelete();

            // The webhook's dedupe wall: purchase:{account}:{provider}:{provider_ref}.
            $table->string('idempotency_key')->unique();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Hot path: a per-account purchase list, and lookup by provider_ref.
            $table->index(['account_id', 'status', 'created_at']);
            $table->index(['provider', 'provider_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
    }
};
