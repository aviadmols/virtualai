<?php

namespace Database\Seeders;

use App\Domain\Sites\StoreCategory;
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
 * (default google/gemini-3.1-flash-image, fallback google/gemini-2.5-flash-image) for
 * try-on; google/gemini-2.5-flash (vision + structured outputs) for scan. The older
 * "-image-preview" id and openai/gpt-image-1 were retired by OpenRouter (404). The
 * admin swaps the default from the Models page toggle (AiModelObserver writes it through).
 */
class AiControlPlaneSeeder extends Seeder
{
    // === CONSTANTS (DB seed DEFAULTS — admin-editable, never read by a service) ===
    private const SCAN_DEFAULT_MODEL = 'google/gemini-2.5-flash';
    private const SCAN_FALLBACK_MODEL = 'openai/gpt-4o-mini';

    private const TRYON_DEFAULT_MODEL = 'google/gemini-3.1-flash-image';
    private const TRYON_FALLBACK_MODEL = 'google/gemini-2.5-flash-image';

    private const TRYON_IMAGE_QUALITY = 'high';
    private const TRYON_ASPECT_RATIO = '3:4';

    // Appended to every try-on user prompt so the result matches the shopper's photo
    // (the model tends to reframe/crop otherwise — the merchant asked for true size).
    private const FRAMING_CLAUSE = 'Return the image at the SAME orientation, framing and aspect ratio as the input photo — do not crop, zoom or reframe the subject; keep the whole figure exactly as uploaded.';

    // Tailored try-on prompts per store type (StoreCategory). Seeded as product_type-scoped
    // defaults the resolver prefers over the generic global prompt; fully admin-editable.
    // The GENERAL category has no entry — it uses the global prompt.
    private const CATEGORY_PROMPTS = [
        StoreCategory::JEWELRY => [
            'system' => 'You generate photorealistic virtual try-on images of JEWELRY. Place the item precisely on the correct body part — rings on a finger, necklaces around the neck, bracelets on the wrist, earrings on the ears — at true scale. Preserve the person\'s skin, hands, pose and background exactly; change nothing except adding the jewelry. Match lighting, reflections and shadows so metal and gemstones look real.',
            'user' => 'Place the {{product_name}} ({{variant}}) from the second image naturally and at realistic scale on the person in the first image. Keep their pose, skin and background; render metal and stones with accurate reflections and shadows.',
        ],
        StoreCategory::CLOTHING => [
            'system' => 'You generate photorealistic virtual try-on images of CLOTHING. Dress the person in the garment so it fits their body and pose naturally, with realistic drape, folds and fabric texture. Preserve the person\'s face, hair, body proportions and background; replace only the relevant clothing.',
            'user' => 'Dress the person in the first image in {{product_name}} ({{variant}}) from the second image. The person is {{height}} cm tall — fit the garment to their proportions, with natural drape and correct length. Keep their face, pose and background.',
        ],
        StoreCategory::FOOTWEAR => [
            'system' => 'You generate photorealistic virtual try-on images of FOOTWEAR. Put the shoes on the person\'s feet matching their stance, foot angle and the ground perspective, with realistic scale, contact shadows and material. Preserve the person and background; change only the footwear.',
            'user' => 'Put the {{product_name}} ({{variant}}) from the second image on the feet of the person in the first image. The person is {{height}} cm tall. Match their pose, the floor perspective and lighting.',
        ],
        StoreCategory::EYEWEAR => [
            'system' => 'You generate photorealistic virtual try-on images of EYEWEAR. Place the glasses or sunglasses on the person\'s face aligned to their eyes, nose bridge and ears, scaled to their face and head angle, with realistic lens reflections and shadows. Preserve the face and everything else.',
            'user' => 'Place the {{product_name}} ({{variant}}) from the second image on the face of the person in the first image, aligned to their eyes and matching their head angle and lighting.',
        ],
        StoreCategory::ACCESSORIES => [
            'system' => 'You generate photorealistic virtual try-on images of ACCESSORIES (bags, watches, hats, belts, scarves). Place the item where it is worn or carried — watch on the wrist, bag on the shoulder or in hand, hat on the head — at true scale with realistic shadows. Preserve the person, pose and background.',
            'user' => 'Place the {{product_name}} ({{variant}}) from the second image on the person in the first image where it is naturally worn or carried, at realistic scale and lighting. Keep their pose and background.',
        ],
        StoreCategory::FURNITURE => [
            'system' => 'You generate photorealistic images that place FURNITURE into a real room photo. Position the item with correct perspective, scale, lighting and contact shadows so it looks genuinely present in the space. Preserve the room, walls, floor and existing items; only add the furniture.',
            'user' => 'Place the {{product_name}} ({{variant}}) from the second image realistically into the room in the first image — correct perspective, scale, lighting and shadows.',
        ],
        StoreCategory::HOME_DECOR => [
            'system' => 'You generate photorealistic images that place HOME DECOR items (lamps, rugs, wall art, vases, cushions) into a real room photo, with correct perspective, scale, lighting and shadows. Preserve the room and only add the item.',
            'user' => 'Place the {{product_name}} ({{variant}}) from the second image realistically into the scene in the first image — matching perspective, scale and lighting.',
        ],
    ];

    public function run(): void
    {
        $this->seedScanOperation();
        $this->seedTryOnOperation();
        $this->seedCategoryPrompts();
    }

    /** Seed a product_type-scoped try-on prompt per store category (admin-editable). */
    private function seedCategoryPrompts(): void
    {
        foreach (self::CATEGORY_PROMPTS as $category => $prompt) {
            Prompt::updateOrCreate(
                [
                    'scope' => Prompt::SCOPE_PRODUCT_TYPE,
                    'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
                    'product_type' => $category,
                    'account_id' => null,
                    'site_id' => null,
                ],
                [
                    'system_prompt' => $prompt['system'],
                    'user_prompt' => $prompt['user'].' '.self::FRAMING_CLAUSE,
                    'version' => 1,
                    'is_active' => true,
                ],
            );
        }
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

        $this->seedModel(AiOperation::KEY_TRY_ON_GENERATION, self::TRYON_DEFAULT_MODEL, 'Gemini 3.1 Flash Image', isDefault: true, costHint: 60_000, unit: AiModel::UNIT_PER_IMAGE);
        $this->seedModel(AiOperation::KEY_TRY_ON_GENERATION, self::TRYON_FALLBACK_MODEL, 'Gemini 2.5 Flash Image', isFallback: true, costHint: 40_000, unit: AiModel::UNIT_PER_IMAGE);

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
                'user_prompt' => 'Generate a realistic try-on of {{product_name}} ({{variant}}) on the person in the first image, using the product in the second image. The person is {{height}} cm tall. Match lighting and perspective. '.self::FRAMING_CLAUSE,
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
