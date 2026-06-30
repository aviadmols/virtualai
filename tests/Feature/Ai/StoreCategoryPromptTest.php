<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Sites\StoreCategory;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Site;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A site's store type (StoreCategory) selects a tailored try-on prompt: the category feeds
 * the resolver's product_type leg, so the seeded product_type-scoped prompt wins over the
 * generic global one. An unset category falls back to the global prompt.
 */
class StoreCategoryPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
    }

    private function site(?string $category): Site
    {
        $account = Account::factory()->create();

        return Site::factory()->forAccount($account)->create(['product_category' => $category]);
    }

    public function test_jewelry_store_resolves_the_jewelry_prompt(): void
    {
        $site = $this->site(StoreCategory::JEWELRY);

        $config = app(AiOperationResolver::class)
            ->for(AiOperation::KEY_TRY_ON_GENERATION, $site, $site->product_category);

        $rendered = $config->substituteUser(['product_name' => 'Sapphire Ring', 'variant' => 'Gold', 'height' => '']);
        $this->assertStringContainsString('metal and stones', $rendered);
    }

    public function test_furniture_store_resolves_the_furniture_prompt(): void
    {
        $site = $this->site(StoreCategory::FURNITURE);

        $config = app(AiOperationResolver::class)
            ->for(AiOperation::KEY_TRY_ON_GENERATION, $site, $site->product_category);

        $rendered = $config->substituteUser(['product_name' => 'Oak Chair', 'variant' => 'Walnut', 'height' => '']);
        $this->assertStringContainsString('room', $rendered);
    }

    public function test_no_category_falls_back_to_the_global_prompt(): void
    {
        $site = $this->site(null);

        // With no category and no product_type, the generic global try-on prompt resolves.
        $config = app(AiOperationResolver::class)
            ->for(AiOperation::KEY_TRY_ON_GENERATION, $site, null);

        $rendered = $config->substituteUser(['product_name' => 'Thing', 'variant' => 'X', 'height' => '170']);
        $this->assertStringContainsString('try-on', strtolower($rendered));
    }

    public function test_category_helpers(): void
    {
        $this->assertTrue(StoreCategory::isValid(StoreCategory::JEWELRY));
        $this->assertFalse(StoreCategory::isValid('nope'));
        $this->assertFalse(StoreCategory::asksHeight(StoreCategory::JEWELRY));
        $this->assertTrue(StoreCategory::asksHeight(StoreCategory::CLOTHING));
        $this->assertArrayHasKey(StoreCategory::FURNITURE, StoreCategory::options());
    }
}
