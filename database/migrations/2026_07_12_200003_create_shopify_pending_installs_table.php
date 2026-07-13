<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shopify_pending_installs — the PRE-BIND parking spot for an install that started on
 * Shopify (`install_new_shop`), before a Tray On account exists.
 *
 * PLATFORM-level (no account_id) by necessity: at callback time there is no tenant to
 * bind — the merchant has not registered/logged in yet. The row is short-lived
 * (expires_at), carries the offline token ENCRYPTED (EncryptedJson under
 * TENANT_CREDENTIALS_KEY, never plaintext at rest), is claimable only with the opaque
 * claim token (stored HASHED — a leaked DB row alone cannot claim it), and is DELETED
 * the moment an authenticated account consumes it. Exactly one pending install per shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_pending_installs', function (Blueprint $table) {
            $table->id();

            // One parked install per store; a restarted install replaces the row.
            $table->string('shop_domain')->unique();

            // sha256 of the opaque claim token handed to the merchant's browser.
            $table->string('claim_token_hash')->unique();

            // Encrypted blob: access_token, scopes, api_version (EncryptedJson).
            $table->text('credentials');

            // Minted at the OAuth callback; carried into the connection's activity trail.
            $table->string('correlation_id')->index();

            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_pending_installs');
    }
};
