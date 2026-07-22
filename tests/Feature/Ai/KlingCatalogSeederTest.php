<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\KlingCatalog;
use App\Models\AiModel;
use App\Models\AiOperation;
use Database\Seeders\AiControlPlaneSeeder;
use Database\Seeders\KlingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Kling catalog: the models an admin can actually SELECT.
 *
 * The ids are PINNED literally here. A fabricated id (the `Dola-Seedream-5.0-lite` scar — it 404'd
 * every try-on in production) fails this test, and so does an id drifting off KlingCatalog's own
 * verified enums. The seed also may never hand a default away from fal, may never leave a Kling row
 * price-less (a flat-rate row with no hint cannot be charged), and must be idempotent on a prod DB.
 */
class KlingCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    // The VERIFIED Kling image ids (the /v1/images/generations model_name enum) + their I2I price
    // hints in micro-USD. Pinned: this list IS the contract.
    private const EXPECTED_IMAGE_MODELS = [
        'kling-v3' => 28_000,
        'kling-v2-1' => 28_000,
        'kling-v2-new' => 28_000,
        'kling-v2' => 28_000,
        'kling-v1-5' => 28_000,
        'kling-v1' => 3_500,
    ];

    private const EXPECTED_IMAGE_OPERATIONS = [
        AiOperation::KEY_TRY_ON_GENERATION,
        AiOperation::KEY_BANNER_GENERATION,
        AiOperation::KEY_PACKSHOT_GENERATION,
        AiOperation::KEY_ON_MODEL_GENERATION,
        AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
    ];

    // The LEGACY-surface video ids KlingVideoClient can actually call, hinted at the 1080p
    // per-second rate x the 5s default clip.
    private const EXPECTED_VIDEO_MODELS = [
        'kling-v2-6' => 700_000,
        'kling-v2-5-turbo' => 350_000,
        'kling-v2-1-master' => 1_400_000,
        'kling-v2-1' => 490_000,
        'kling-v1-6' => 490_000,
        'kling-v1-5' => 490_000,
        'kling-v1' => 490_000,
    ];

    // Deliberately NOT seeded as video models (see KlingCatalogSeeder's docblock):
    //   kling-v3        — Kling 3.0 VIDEO is a different, path-based API our client cannot speak.
    //   kling-v2-master — no verified price; a hint may never be invented.
    private const NOT_SEEDED_AS_VIDEO = ['kling-v3', 'kling-v2-master', 'kling-3.0-turbo'];

    /** @return array<int,string> */
    private function klingIds(string $operationKey): array
    {
        return AiModel::query()
            ->where('operation_key', $operationKey)
            ->where('provider', AiModel::PROVIDER_KLING)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->all();
    }

    public function test_the_migration_already_seeded_the_verified_image_models_on_every_image_operation(): void
    {
        // No explicit seed call: the seed MIGRATION runs for every test, so a deployed system has
        // the catalog without a manual seeder run.
        foreach (self::EXPECTED_IMAGE_OPERATIONS as $operationKey) {
            $expected = array_keys(self::EXPECTED_IMAGE_MODELS);
            sort($expected);

            $this->assertSame($expected, $this->klingIds($operationKey), 'Kling image ids drifted on '.$operationKey);
        }
    }

    public function test_every_seeded_image_model_carries_its_verified_price_hint_and_unit(): void
    {
        foreach (self::EXPECTED_IMAGE_MODELS as $modelId => $hint) {
            $model = AiModel::query()
                ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
                ->where('model_id', $modelId)
                ->firstOrFail();

            $this->assertSame(AiModel::PROVIDER_KLING, $model->provider);
            $this->assertSame($hint, $model->cost_hint_micro_usd);
            $this->assertSame(AiModel::UNIT_PER_IMAGE, $model->cost_unit);
            $this->assertTrue($model->is_active);
            $this->assertNotSame('', (string) $model->label);
        }
    }

    public function test_the_verified_legacy_surface_video_models_are_seeded_on_the_clip_step(): void
    {
        $expected = array_keys(self::EXPECTED_VIDEO_MODELS);
        sort($expected);

        $this->assertSame($expected, $this->klingIds(AiOperation::KEY_STORYBOARD_CLIP));

        foreach (self::EXPECTED_VIDEO_MODELS as $modelId => $hint) {
            $model = AiModel::query()
                ->where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
                ->where('model_id', $modelId)
                ->firstOrFail();

            $this->assertSame($hint, $model->cost_hint_micro_usd);
            $this->assertSame(AiModel::PROVIDER_KLING, $model->provider);
        }
    }

    public function test_the_unverifiable_video_ids_are_never_seeded_as_video_models(): void
    {
        foreach (self::NOT_SEEDED_AS_VIDEO as $modelId) {
            $this->assertNotContains(
                $modelId,
                $this->klingIds(AiOperation::KEY_STORYBOARD_CLIP),
                $modelId.' must not be catalogued on the legacy video surface.',
            );
        }

        // kling-v3 IS a verified IMAGE id, so it stays selectable there.
        $this->assertContains('kling-v3', $this->klingIds(AiOperation::KEY_TRY_ON_GENERATION));
    }

    public function test_every_seeded_id_exists_in_the_verified_kling_catalog(): void
    {
        // The anti-fabrication wall: no id may be invented, and none may belong to an API surface
        // this client does not speak.
        foreach (self::EXPECTED_IMAGE_MODELS as $modelId => $hint) {
            $this->assertContains($modelId, KlingCatalog::IMAGE_MODELS);
            $this->assertFalse(KlingCatalog::isUnsupported($modelId));
        }

        foreach (self::EXPECTED_VIDEO_MODELS as $modelId => $hint) {
            $this->assertContains($modelId, KlingCatalog::VIDEO_MODELS);
            $this->assertFalse(KlingCatalog::isUnsupported($modelId));
        }
    }

    public function test_a_fabricated_id_can_never_be_seeded(): void
    {
        // Prove the seeder's own wall: an id outside KlingCatalog's verified enum fails LOUD here
        // rather than 404ing on the first real generation (the Seedream scar).
        $this->expectException(\RuntimeException::class);

        (new class extends KlingCatalogSeeder
        {
            public function run(): void
            {
                $this->assertVerified('kling-v9-imaginary');
            }
        })->run();
    }

    public function test_kling_defaults_exist_only_on_the_storyboard_engine(): void
    {
        // The storyboard engine (frames + clips) deliberately runs Kling-native — the chained
        // single-reference images + the image_tail shot connection live there. Every OTHER
        // surface keeps its fal/OpenRouter default: pointing one at Kling stays an admin act.
        $flaggedOps = AiModel::query()
            ->where('provider', AiModel::PROVIDER_KLING)
            ->where(fn ($q) => $q->where('is_default', true)->orWhere('is_fallback', true))
            ->pluck('operation_key')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [AiOperation::KEY_STORYBOARD_CLIP, AiOperation::KEY_STORYBOARD_FRAME_IMAGE],
            $flaggedOps,
        );
    }

    public function test_no_kling_model_is_seeded_without_a_positive_price(): void
    {
        // A flat-rate row with no price can never produce an honest charge — AiModelResource itself
        // refuses to save one, so the seed may not create one either.
        $priceless = AiModel::query()
            ->where('provider', AiModel::PROVIDER_KLING)
            ->where(fn ($q) => $q->whereNull('cost_hint_micro_usd')->orWhere('cost_hint_micro_usd', '<=', 0))
            ->count();

        $this->assertSame(0, $priceless);
    }

    public function test_the_seeder_is_idempotent_on_a_re_run(): void
    {
        $before = AiModel::query()->where('provider', AiModel::PROVIDER_KLING)
            ->orderBy('id')->get(['id', 'operation_key', 'model_id', 'cost_hint_micro_usd', 'label'])->toArray();

        $this->assertNotEmpty($before);

        (new KlingCatalogSeeder)->run();
        (new KlingCatalogSeeder)->run();

        $after = AiModel::query()->where('provider', AiModel::PROVIDER_KLING)
            ->orderBy('id')->get(['id', 'operation_key', 'model_id', 'cost_hint_micro_usd', 'label'])->toArray();

        // Same rows, same ids, same values — a re-run on a prod DB duplicates nothing.
        $this->assertSame($before, $after);
    }

    public function test_a_re_run_never_steals_a_default_from_another_provider(): void
    {
        // The control plane's own defaults (OpenRouter/fal) must survive a Kling re-seed untouched.
        $this->seed(AiControlPlaneSeeder::class);

        $default = AiModel::query()
            ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->where('is_default', true)
            ->firstOrFail();

        $this->assertNotSame(AiModel::PROVIDER_KLING, $default->provider);

        (new KlingCatalogSeeder)->run();

        $this->assertSame($default->id, AiModel::query()
            ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->where('is_default', true)
            ->value('id'));
    }
}
