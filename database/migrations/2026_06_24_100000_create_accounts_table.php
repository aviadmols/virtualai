<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts — THE TENANT. Holds credit balance + status. NOT account-scoped
 * (it is the isolation boundary itself). Credit columns are integer micro-USD
 * (never floats); the ledger logic lands in Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // active | suspended — gates whether jobs may run for this tenant.
            $table->string('status')->default('active')->index();

            // Credit selling-value, integer micro-USD. Columns declared now;
            // the ledger + reservation logic is Phase 5.
            $table->bigInteger('balance_micro_usd')->default(0);
            $table->bigInteger('reserved_micro_usd')->default(0);

            // Default panel + email language for the account owner.
            $table->string('locale', 5)->default('en');

            // Billing / contact surface (kept minimal for Phase 2).
            $table->string('billing_email')->nullable();
            $table->string('company_name')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
