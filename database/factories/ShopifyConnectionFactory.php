<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyConnection>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant
 * (the BannerAssetFactory convention); use forSite() for a coherent site+account
 * pair. The credentials blob carries a fake offline token — encrypted at rest by
 * the EncryptedJson cast.
 */
class ShopifyConnectionFactory extends Factory
{
    protected $model = ShopifyConnection::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'shop_domain' => fake()->unique()->domainWord().'.myshopify.com',
            'status' => ShopifyConnection::STATUS_INSTALLED,
            'credentials' => [
                ShopifyConnection::CRED_ACCESS_TOKEN => 'shpat_'.fake()->sha1(),
                ShopifyConnection::CRED_SCOPES => (string) config('shopify.scopes'),
                ShopifyConnection::CRED_API_VERSION => (string) config('shopify.api_version'),
            ],
            'installed_at' => now(),
        ];
    }

    /** Build the connection for an existing site (and its account). */
    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'account_id' => $site->account_id,
            'site_id' => $site->id,
        ]);
    }
}
