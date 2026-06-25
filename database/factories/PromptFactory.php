<?php

namespace Database\Factories;

use App\Models\AiOperation;
use App\Models\Prompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Prompt>
 */
class PromptFactory extends Factory
{
    protected $model = Prompt::class;

    public function definition(): array
    {
        return [
            'scope' => Prompt::SCOPE_GLOBAL,
            'operation_key' => AiOperation::KEY_PRODUCT_SCAN,
            'product_type' => null,
            'account_id' => null,
            'site_id' => null,
            'system_prompt' => 'You are a product extraction assistant.',
            'user_prompt' => 'Extract product data for {{product_name}}.',
            'version' => 1,
            'is_active' => true,
        ];
    }

    public function globalScope(): static
    {
        return $this->state(fn () => [
            'scope' => Prompt::SCOPE_GLOBAL,
            'product_type' => null,
            'account_id' => null,
            'site_id' => null,
        ]);
    }

    public function productTypeScope(string $productType): static
    {
        return $this->state(fn () => [
            'scope' => Prompt::SCOPE_PRODUCT_TYPE,
            'product_type' => $productType,
            'account_id' => null,
            'site_id' => null,
        ]);
    }

    public function accountScope(int $accountId): static
    {
        return $this->state(fn () => [
            'scope' => Prompt::SCOPE_ACCOUNT,
            'account_id' => $accountId,
            'site_id' => null,
        ]);
    }

    public function siteScope(int $accountId, int $siteId): static
    {
        return $this->state(fn () => [
            'scope' => Prompt::SCOPE_SITE,
            'account_id' => $accountId,
            'site_id' => $siteId,
        ]);
    }
}
