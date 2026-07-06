<?php

namespace Database\Factories;

use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BannerAsset>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant. A fresh
 * asset is always status=pending with a production-shaped (banner:...) idempotency key.
 */
class BannerAssetFactory extends Factory
{
    protected $model = BannerAsset::class;

    public function definition(): array
    {
        $clientRequestId = 'crq_'.Str::random(16);

        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'banner_id' => Banner::factory(),
            'status' => BannerAsset::STATUS_PENDING,
            'client_request_id' => $clientRequestId,
            'idempotency_key' => 'banner:'.Str::random(40).':'.$clientRequestId,
            'brief' => 'A bold summer sale banner, warm colors.',
        ];
    }

    /** Build a coherent asset under a real banner with the production-shaped key. */
    public function forBanner(Banner $banner, string $clientRequestId = 'crq_test'): static
    {
        return $this->state(fn () => [
            'account_id' => $banner->account_id,
            'site_id' => $banner->site_id,
            'banner_id' => $banner->getKey(),
            'client_request_id' => $clientRequestId,
            'idempotency_key' => IdempotencyKey::forBanner(
                accountId: (int) $banner->account_id,
                siteId: (int) $banner->site_id,
                bannerId: (int) $banner->getKey(),
                clientRequestId: $clientRequestId,
            ),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => BannerAsset::STATUS_PROCESSING]);
    }
}
