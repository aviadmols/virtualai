<?php

namespace Database\Factories;

use App\Models\ShopifyPendingInstall;
use App\Support\CorrelationId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyPendingInstall>
 *
 * A freshly parked install-from-Shopify: encrypted token, hashed claim token, one hour
 * to live. Platform-level — no account/tenant involved (the whole point of the row).
 * withClaimToken() pins the plaintext so a test can claim it.
 */
class ShopifyPendingInstallFactory extends Factory
{
    protected $model = ShopifyPendingInstall::class;

    public function definition(): array
    {
        return [
            'shop_domain' => fake()->unique()->domainWord().'.myshopify.com',
            'claim_token_hash' => ShopifyPendingInstall::hashClaimToken(ShopifyPendingInstall::generateClaimToken()),
            'credentials' => [
                ShopifyPendingInstall::CRED_ACCESS_TOKEN => 'shpat_'.fake()->sha1(),
                ShopifyPendingInstall::CRED_SCOPES => (string) config('shopify.scopes'),
                ShopifyPendingInstall::CRED_API_VERSION => (string) config('shopify.api_version'),
            ],
            'correlation_id' => CorrelationId::mint(),
            'expires_at' => now()->addMinutes(ShopifyPendingInstall::TTL_MINUTES),
        ];
    }

    /** Park the install under a KNOWN plaintext claim token. */
    public function withClaimToken(string $plain): static
    {
        return $this->state(fn (): array => [
            'claim_token_hash' => ShopifyPendingInstall::hashClaimToken($plain),
        ]);
    }

    /** An install whose claim window has lapsed (must never be consumable). */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
