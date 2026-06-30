<?php

namespace Tests\Feature\Platform;

use App\Domain\Ai\AiOperationResolver;
use App\Models\AiModel;
use App\Models\AiOperation;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiModelObserver — the Models-page is_default/is_fallback toggle is the authoring surface:
 * it writes through to ai_operations.default_model/fallback_model (what the resolver reads)
 * and keeps exactly one default + one fallback per operation. The resolver is unchanged.
 */
class AiModelObserverTest extends TestCase
{
    use RefreshDatabase;

    private const OP = 'try_on_generation';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
    }

    private function defaultModel(): ?string
    {
        return AiOperation::query()->where('operation_key', self::OP)->value('default_model');
    }

    private function defaultCount(): int
    {
        return AiModel::query()->where('operation_key', self::OP)->where('is_default', true)->count();
    }

    public function test_seeder_sets_gemini_31_as_the_one_default(): void
    {
        $this->assertSame('google/gemini-3.1-flash-image', $this->defaultModel());
        $this->assertSame(1, $this->defaultCount());
    }

    public function test_toggling_is_default_writes_through_to_operation_and_resolver(): void
    {
        // The merchant's exact action: flip Default on the 2.5 catalog row.
        $model = AiModel::query()->where('operation_key', self::OP)
            ->where('model_id', 'google/gemini-2.5-flash-image')->firstOrFail();
        $model->is_default = true;
        $model->save();

        // The operation column (what the resolver reads) now points at the chosen model...
        $this->assertSame('google/gemini-2.5-flash-image', $this->defaultModel());
        $this->assertSame('google/gemini-2.5-flash-image', app(AiOperationResolver::class)->for(self::OP)->model);
        // ...and exactly one default remains (the previous one was unseated).
        $this->assertSame(1, $this->defaultCount());
    }

    public function test_toggling_is_fallback_writes_through(): void
    {
        $model = AiModel::query()->where('operation_key', self::OP)
            ->where('model_id', 'google/gemini-3.1-flash-image')->firstOrFail();
        $model->is_fallback = true;
        $model->save();

        $this->assertSame('google/gemini-3.1-flash-image', AiOperation::query()->where('operation_key', self::OP)->value('fallback_model'));
        $this->assertSame(1, AiModel::query()->where('operation_key', self::OP)->where('is_fallback', true)->count());
    }

    public function test_deactivating_the_default_recomputes_the_operation_column(): void
    {
        $model = AiModel::query()->where('operation_key', self::OP)
            ->where('model_id', 'google/gemini-3.1-flash-image')->firstOrFail();
        $model->is_active = false;
        $model->save();

        // No active default remains -> the column nulls out (resolver then fails loud, never stale).
        $this->assertNull($this->defaultModel());
    }

    public function test_reseed_keeps_exactly_one_default_and_does_not_fight_the_observer(): void
    {
        $this->seed(AiControlPlaneSeeder::class); // simulate a redeploy re-running the seeder

        $this->assertSame('google/gemini-3.1-flash-image', $this->defaultModel());
        $this->assertSame(1, $this->defaultCount());
    }
}
