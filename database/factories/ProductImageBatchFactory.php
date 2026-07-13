<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\ProductImageBatch;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImageBatch>
 *
 * Sets account_id + site_id explicitly so the row builds without a bound tenant (and so a
 * chained factory never mints a FOREIGN account under a bound one — TS-TENANCY-005).
 */
class ProductImageBatchFactory extends Factory
{
    protected $model = ProductImageBatch::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'site_id' => Site::factory(),
            'operation_key' => AiOperation::KEY_PACKSHOT_GENERATION,
            'source_pick' => ProductImageBatch::SOURCE_MAIN,
            'status' => ProductImageBatch::STATUS_PENDING,
            'total' => 0,
            'correlation_id' => (string) Str::ulid(),
        ];
    }

    /** A coherent batch under a real site (account inherited — never a fresh one). */
    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'account_id' => $site->account_id,
            'site_id' => $site->getKey(),
        ]);
    }

    public function running(int $total = 1): static
    {
        return $this->state(fn () => [
            'status' => ProductImageBatch::STATUS_RUNNING,
            'total' => $total,
            'started_at' => now(),
        ]);
    }
}
