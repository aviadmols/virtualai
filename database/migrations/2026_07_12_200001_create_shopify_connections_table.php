<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_connections — one Shopify store's link to a Site (1:1). Tenant-owned.
 *
 * `shop_domain` is GLOBALLY unique: it is the pre-bind webhook routing key (the
 * ShopifyShopRouter's lookup column — a webhook arrives with no bound tenant).
 * `credentials` is the encrypted per-store secret blob (offline access token,
 * granted scopes, API version at install) — EncryptedJson / TENANT_CREDENTIALS_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connections', function (Blueprint $table) {
            $table->id();

            // Tenancy — the isolation boundary. site is 1:1 (one store = one Site).
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();

            // The pre-bind routing key ({shop}.myshopify.com — globally unique).
            $table->string('shop_domain')->unique();

            // installed | uninstalled. Guarded transitions (re-install re-activates).
            $table->string('status')->default('installed')->index();

            // Encrypted blob: access_token, scopes, api_version (EncryptedJson).
            $table->text('credentials')->nullable();

            // Admin API returned 401 — token revoked/expired; merchant must re-connect.
            $table->boolean('needs_reauth')->default(false);

            // topic => Shopify webhook-subscription id (registration bookkeeping).
            $table->json('webhook_registration')->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_connections');
    }
};
