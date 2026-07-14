<?php

namespace Database\Seeders;

use App\Domain\Ai\KlingCatalog;
use App\Models\AiModel;
use App\Models\AiOperation;
use Illuminate\Database\Seeder;

/**
 * KlingCatalogSeeder — catalogs the Kling (Kuaishou) models so an admin can actually SELECT one.
 *
 * The Kling client shipped with no catalog at all: the provider existed, the key field existed, and
 * the Models screen offered nothing. This seeds `ai_models` rows for provider `kling` — active and
 * selectable, but NEVER default/fallback (fal keeps every default; switching an operation to Kling
 * stays a deliberate admin act from the DB, no redeploy).
 *
 * MODEL IDS ARE NEVER INVENTED. Every id here is read from KlingCatalog's own verified enums (the
 * fabricated `Dola-Seedream-5.0-lite` 404'd every try-on in production — that scar is not re-earned).
 * Two ids in KlingCatalog::VIDEO_MODELS are deliberately NOT seeded as video models:
 *   - `kling-v3`      — Kling 3.0 VIDEO is a different, path-based API (contents[] + settings{}) that
 *                       KlingVideoClient cannot speak, and the legacy /v1/videos enum could not be
 *                       verified. It is seeded IMAGE-ONLY (its image id IS verified).
 *   - `kling-v2-master` — no verified price. Inventing a price hint is the Seedream scar in price
 *                       form, and AiModelResource itself refuses a Kling row without a positive one.
 * Both stay typeable: the Models page + the Playground offer KlingCatalog's ids as suggestions.
 *
 * PRICES are the admin-tunable RESERVATION ESTIMATE only. The real charge is what Kling bills on the
 * task (final_balance_deduction.list_price / billing[].list_price → KlingCost) — the hint is used
 * only when a response carries no cash price (a resource-package account), and with neither the
 * money path fails closed. Hints below are from the Kling console pricing tables (2026-07-13).
 *
 * Idempotent: updateOrCreate on the (operation_key, model_id) unique key — safe to re-run on prod.
 */
class KlingCatalogSeeder extends Seeder
{
    // === CONSTANTS ===
    // IMAGE hints (micro-USD per image) — the IMAGE-TO-IMAGE figure, because every Vsio rail
    // sends input images and Kling prices I2I at double T2I ($0.028 vs $0.014).
    private const IMAGE_MODELS = [
        'kling-v3' => ['label' => 'Kling Image 3.0', 'cost' => 28_000],
        'kling-v2-1' => ['label' => 'Kling Image 2.1', 'cost' => 28_000],
        'kling-v2-new' => ['label' => 'Kling Image 2.0 New', 'cost' => 28_000],
        'kling-v2' => ['label' => 'Kling Image 2.0', 'cost' => 28_000],
        'kling-v1-5' => ['label' => 'Kling Image 1.5', 'cost' => 28_000],
        'kling-v1' => ['label' => 'Kling Image 1.0', 'cost' => 3_500],
    ];

    // The image operations an admin may point at Kling (every operation whose output is an image).
    private const IMAGE_OPERATIONS = [
        AiOperation::KEY_TRY_ON_GENERATION,
        AiOperation::KEY_BANNER_GENERATION,
        AiOperation::KEY_PACKSHOT_GENERATION,
        AiOperation::KEY_ON_MODEL_GENERATION,
        AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
    ];

    // VIDEO hints (micro-USD per clip). Kling bills video PER SECOND, per resolution, so the hint is
    // the 1080p per-second rate × the DEFAULT_CLIP_SECONDS clip the callers request.
    private const DEFAULT_CLIP_SECONDS = 5;

    // model id => [label, micro-USD PER SECOND at 1080p]
    private const VIDEO_MODELS = [
        'kling-v2-6' => ['label' => 'Kling 2.6 (video)', 'per_second' => 140_000],
        'kling-v2-5-turbo' => ['label' => 'Kling 2.5 Turbo (video)', 'per_second' => 70_000],
        'kling-v2-1-master' => ['label' => 'Kling 2.1 Master (video)', 'per_second' => 280_000],
        'kling-v2-1' => ['label' => 'Kling 2.1 (video)', 'per_second' => 98_000],
        'kling-v1-6' => ['label' => 'Kling 1.6 (video)', 'per_second' => 98_000],
        'kling-v1-5' => ['label' => 'Kling 1.5 (video)', 'per_second' => 98_000],
        'kling-v1' => ['label' => 'Kling 1.0 (video)', 'per_second' => 98_000],
    ];

    // The one operation that runs a video model today (the storyboard clip step).
    private const VIDEO_OPERATION = AiOperation::KEY_STORYBOARD_CLIP;

    public function run(): void
    {
        $this->seedImageModels();
        $this->seedVideoModels();
    }

    /** The six verified Kling image ids, catalogued on every image operation. */
    private function seedImageModels(): void
    {
        foreach (self::IMAGE_OPERATIONS as $operationKey) {
            foreach (self::IMAGE_MODELS as $modelId => $spec) {
                $this->assertVerified($modelId, KlingCatalog::IMAGE_MODELS);

                $this->seedModel($operationKey, $modelId, $spec['label'], $spec['cost']);
            }
        }
    }

    /** The legacy-surface video ids (the ones KlingVideoClient can actually call), on the clip step. */
    private function seedVideoModels(): void
    {
        foreach (self::VIDEO_MODELS as $modelId => $spec) {
            $this->assertVerified($modelId, KlingCatalog::VIDEO_MODELS);

            $this->seedModel(
                self::VIDEO_OPERATION,
                $modelId,
                $spec['label'],
                $spec['per_second'] * self::DEFAULT_CLIP_SECONDS,
            );
        }
    }

    /**
     * A seeded id must exist in KlingCatalog's verified enum for THAT surface AND not belong to an
     * API surface this client cannot speak. A fabricated or drifted id fails LOUD here rather than
     * 404ing on the first real generation (the Seedream scar).
     *
     * @param  array<int,string>  $verifiedIds
     */
    protected function assertVerified(string $modelId, array $verifiedIds = KlingCatalog::IMAGE_MODELS): void
    {
        if (! in_array($modelId, $verifiedIds, true) || KlingCatalog::isUnsupported($modelId)) {
            throw new \RuntimeException(sprintf('Kling model "%s" is not a verified id for this surface.', $modelId));
        }
    }

    /** Active + selectable, never a default/fallback (fal keeps every default). */
    private function seedModel(string $operationKey, string $modelId, string $label, int $costHint): void
    {
        AiModel::updateOrCreate(
            ['operation_key' => $operationKey, 'model_id' => $modelId],
            [
                'provider' => AiModel::PROVIDER_KLING,
                'label' => $label,
                'is_default' => false,
                'is_fallback' => false,
                'cost_hint_micro_usd' => $costHint,
                'cost_unit' => AiModel::UNIT_PER_IMAGE,
                'is_active' => true,
            ],
        );
    }
}
