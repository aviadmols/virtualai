<?php

namespace Database\Factories;

use App\Models\ShopifyWebhookReceipt;
use App\Support\CorrelationId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyWebhookReceipt>
 *
 * A freshly-received delivery: status=received, zero attempts, a payload body.
 * Platform-level — no account/tenant involved.
 */
class ShopifyWebhookReceiptFactory extends Factory
{
    protected $model = ShopifyWebhookReceipt::class;

    public function definition(): array
    {
        return [
            'webhook_id' => fake()->unique()->uuid(),
            'topic' => 'products/update',
            'shop_domain' => fake()->domainWord().'.myshopify.com',
            'status' => ShopifyWebhookReceipt::STATUS_RECEIVED,
            'payload' => ['id' => fake()->randomNumber(8)],
            'attempts' => 0,
            'correlation_id' => CorrelationId::mint(),
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => ['status' => ShopifyWebhookReceipt::STATUS_QUEUED, 'attempts' => 1]);
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => ShopifyWebhookReceipt::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }
}
