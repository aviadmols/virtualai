<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Prompt;
use Illuminate\Database\Seeder;

/**
 * AiControlPlaneSeeder — DB DEFAULTS for the AI control plane.
 *
 * Every value here is an admin-editable DEFAULT, not a hardcoded service literal:
 * Super-Admin edits ai_operations / ai_models / prompts from the DB without a
 * redeploy. The seeder makes the plane work out of the box:
 *  - a try_on_generation operation with a strong image model + fallback,
 *  - a product_scan operation with a vision/JSON model + fallback,
 *  - scope=global prompts for BOTH (the guaranteed resolution floor).
 *
 * Model ids verified current on OpenRouter (2026-06-30): the Gemini image line
 * (google/gemini-2.5-flash-image, fallback google/gemini-3.1-flash-image) for try-on;
 * google/gemini-2.5-flash (vision + structured outputs) for scan. The older
 * "-image-preview" id and openai/gpt-image-1 were retired by OpenRouter (404). Admin
 * can swap any of these later from the panel.
 */
class AiControlPlaneSeeder extends Seeder
{
    // === CONSTANTS (DB seed DEFAULTS — admin-editable, never read by a service) ===
    private const SCAN_DEFAULT_MODEL = 'google/gemini-2.5-flash';
    private const SCAN_FALLBACK_MODEL = 'openai/gpt-4o-mini';

    private const TRYON_DEFAULT_MODEL = 'google/gemini-2.5-flash-image';
    private const TRYON_FALLBACK_MODEL = 'google/gemini-3.1-flash-image';

    private const TRYON_IMAGE_QUALITY = 'high';
    private const TRYON_ASPECT_RATIO = '3:4';

    public function run(): void
    {
        $this->seedScanOperation();
        $this->seedTryOnOperation();
    }

    private function seedScanOperation(): void
    {
        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_PRODUCT_SCAN],
            [
                'label' => 'Product Scan',
                'default_model' => self::SCAN_DEFAULT_MODEL,
                'fallback_model' => self::SCAN_FALLBACK_MODEL,
                'image_quality' => null,
                'aspect_ratio' => null,
                // Deterministic extraction: temperature 0 + bounded tokens.
                'params' => ['temperature' => 0, 'top_p' => 1, 'max_tokens' => 4096],
                'input_schema' => $this->scanSchema(),
                'retention_days' => 30,
                'estimated_cost_micro_usd' => 3_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_PRODUCT_SCAN, self::SCAN_DEFAULT_MODEL, 'Gemini 2.5 Flash', isDefault: true, costHint: 3_000, unit: AiModel::UNIT_PER_1K_TOKENS);
        $this->seedModel(AiOperation::KEY_PRODUCT_SCAN, self::SCAN_FALLBACK_MODEL, 'GPT-4o mini', isFallback: true, costHint: 2_000, unit: AiModel::UNIT_PER_1K_TOKENS);

        Prompt::updateOrCreate(
            [
                'scope' => Prompt::SCOPE_GLOBAL,
                'operation_key' => AiOperation::KEY_PRODUCT_SCAN,
                'product_type' => null,
                'account_id' => null,
                'site_id' => null,
            ],
            [
                'system_prompt' => 'You are a precise e-commerce product extractor. Read the supplied product page representation and return ONLY a JSON object matching the schema. Never invent data; use null when a field is absent.',
                'user_prompt' => "Extract the product, its variants, physical dimensions, and the page selectors for {{product_name}} from the page below.\nReturn strict JSON for the product_scan schema.",
                'version' => 1,
                'is_active' => true,
            ],
        );
    }

    private function seedTryOnOperation(): void
    {
        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_TRY_ON_GENERATION],
            [
                'label' => 'Try-On Generation',
                'default_model' => self::TRYON_DEFAULT_MODEL,
                'fallback_model' => self::TRYON_FALLBACK_MODEL,
                'image_quality' => self::TRYON_IMAGE_QUALITY,
                'aspect_ratio' => self::TRYON_ASPECT_RATIO,
                // Determinism lives here, not in code: a fixed seed makes a try-on
                // reproducible for debugging. Admin can loosen it (raise temperature).
                'params' => ['seed' => 1234, 'temperature' => 0.2, 'top_p' => 0.9],
                'input_schema' => null,
                'retention_days' => 30,
                'estimated_cost_micro_usd' => 40_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_TRY_ON_GENERATION, self::TRYON_DEFAULT_MODEL, 'Gemini 2.5 Flash Image', isDefault: true, costHint: 40_000, unit: AiModel::UNIT_PER_IMAGE);
        $this->seedModel(AiOperation::KEY_TRY_ON_GENERATION, self::TRYON_FALLBACK_MODEL, 'Gemini 3.1 Flash Image', isFallback: true, costHint: 60_000, unit: AiModel::UNIT_PER_IMAGE);

        Prompt::updateOrCreate(
            [
                'scope' => Prompt::SCOPE_GLOBAL,
                'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
                'product_type' => null,
                'account_id' => null,
                'site_id' => null,
            ],
            [
                'system_prompt' => 'You generate photorealistic virtual try-on images. Keep the shopper\'s face, body and pose; place the product naturally and accurately on them.',
                'user_prompt' => 'Generate a realistic try-on of {{product_name}} ({{variant}}) on the person in the first image, using the product in the second image. The person is {{height}} cm tall. Match lighting and perspective.',
                'version' => 1,
                'is_active' => true,
            ],
        );
    }

    private function seedModel(
        string $operationKey,
        string $modelId,
        string $label,
        bool $isDefault = false,
        bool $isFallback = false,
        ?int $costHint = null,
        ?string $unit = null,
    ): void {
        AiModel::updateOrCreate(
            ['operation_key' => $operationKey, 'model_id' => $modelId],
            [
                'label' => $label,
                'is_default' => $isDefault,
                'is_fallback' => $isFallback,
                'cost_hint_micro_usd' => $costHint,
                'cost_unit' => $unit,
                'is_active' => true,
            ],
        );
    }

    /** The strict product_scan JSON schema (additionalProperties:false for strict mode). */
    private function scanSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'product_name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'price' => ['type' => ['number', 'null']],
                'currency' => ['type' => ['string', 'null']],
                'product_type' => ['type' => 'string'],
                'main_image' => ['type' => 'string'],
                'images' => ['type' => 'array', 'items' => ['type' => 'string']],
                'variants' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'axis' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'image' => ['type' => ['string', 'null']],
                            'available' => ['type' => 'boolean'],
                        ],
                        'required' => ['axis', 'value', 'available'],
                    ],
                ],
                'physical_dimensions' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'selectors' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['product_name', 'product_type', 'main_image'],
        ];
    }
}
