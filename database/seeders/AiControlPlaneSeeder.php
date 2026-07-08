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

    // Banner generation reuses the strong Gemini image line (same catalog, image output).
    private const BANNER_DEFAULT_MODEL = 'google/gemini-3.1-flash-image';
    private const BANNER_FALLBACK_MODEL = 'google/gemini-2.5-flash-image';

    // Extra Gemini image models catalogued for banners so the Super-Admin can switch the
    // default from the Models page (all verified live on OpenRouter). Lite = cheaper/faster,
    // Pro = higher quality. Active + selectable; the admin picks which one generates banners.
    private const BANNER_ALT_MODELS = [
        'google/gemini-3.1-flash-lite-image' => ['label' => 'Gemini 3.1 Flash Lite Image', 'cost' => 30_000],
        'google/gemini-3-pro-image' => ['label' => 'Gemini 3 Pro Image', 'cost' => 100_000],
    ];

    // Banners are wide marketing creatives; quality high, a landscape aspect ratio.
    private const BANNER_IMAGE_QUALITY = 'high';
    private const BANNER_ASPECT_RATIO = '16:9';

    // BytePlus/Seedream try-on model, catalogued but INACTIVE — the admin activates it after
    // adding a BytePlus key + a verified per-image cost hint (money-safe by default).
    // `seedream-5-0-260128` is Seedream 5.0 LITE — the only Seedream 5.0 exposed on the
    // ModelArk /images/generations API today (the full 5.0 has no public model id yet).
    // model id (the ModelArk `model`/endpoint id) => label.
    private const SEEDREAM_MODELS = [
        'seedream-5-0-260128' => 'Seedream 5.0 Lite (BytePlus)',
    ];

    // xAI/Grok banner models, catalogued but INACTIVE — the admin activates one after adding an
    // xAI key (Settings) + confirming its per-image price. xAI images/generations is TEXT-TO-IMAGE,
    // so Grok suits BANNERS (not a true try-on). The $0.07/image starter matches grok-2-image's
    // published price; adjust per model. model id (the xAI `model`) => label.
    private const XAI_BANNER_MODELS = [
        'grok-2-image' => 'Grok 2 Image (xAI)',
        'grok-imagine-image-quality' => 'Grok Imagine (xAI)',
    ];

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
        $this->seedBannerOperation();
        $this->seedCategoryPrompts();
    }

    /**
     * Seed the banner_generation operation: a strong image model + fallback, wide aspect,
     * and a scope=global prompt. The merchant's freeform brief is substituted into the
     * user prompt via {{brief}} (strtr, never Blade). No fixed seed — each regenerate
     * should yield a fresh candidate for the merchant to choose from. Fully admin-editable.
     */
    private function seedBannerOperation(): void
    {
        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_BANNER_GENERATION],
            [
                'label' => 'Banner Generation',
                'default_model' => self::BANNER_DEFAULT_MODEL,
                'fallback_model' => self::BANNER_FALLBACK_MODEL,
                'image_quality' => self::BANNER_IMAGE_QUALITY,
                'aspect_ratio' => self::BANNER_ASPECT_RATIO,
                // No fixed seed: variety across regenerations is the point of the candidate flow.
                'params' => ['temperature' => 0.7, 'top_p' => 0.95],
                'input_schema' => null,
                'retention_days' => null, // marketing artwork is not shopper PII; kept until archived
                'estimated_cost_micro_usd' => 40_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_BANNER_GENERATION, self::BANNER_DEFAULT_MODEL, 'Gemini 3.1 Flash Image', isDefault: true, costHint: 60_000, unit: AiModel::UNIT_PER_IMAGE);
        $this->seedModel(AiOperation::KEY_BANNER_GENERATION, self::BANNER_FALLBACK_MODEL, 'Gemini 2.5 Flash Image', isFallback: true, costHint: 40_000, unit: AiModel::UNIT_PER_IMAGE);

        // Extra Gemini image models the Super-Admin can switch to for banners (Models page).
        foreach (self::BANNER_ALT_MODELS as $modelId => $meta) {
            $this->seedModel(AiOperation::KEY_BANNER_GENERATION, $modelId, $meta['label'], costHint: $meta['cost'], unit: AiModel::UNIT_PER_IMAGE);
        }

        // xAI/Grok text-to-image banner models, OFF by default (admin adds an xAI key + confirms price).
        foreach (self::XAI_BANNER_MODELS as $modelId => $label) {
            $this->seedModel(AiOperation::KEY_BANNER_GENERATION, $modelId, $label, costHint: 70_000, unit: AiModel::UNIT_PER_IMAGE, provider: AiModel::PROVIDER_XAI, isActive: false);
        }

        Prompt::updateOrCreate(
            [
                'scope' => Prompt::SCOPE_GLOBAL,
                'operation_key' => AiOperation::KEY_BANNER_GENERATION,
                'product_type' => null,
                'account_id' => null,
                'site_id' => null,
            ],
            [
                'system_prompt' => 'You are a senior e-commerce graphic designer. Generate a single, polished, high-conversion marketing BANNER image for an online store. Compose a clean, uncluttered layout with strong focal hierarchy and generous negative space, so a headline and a call-to-action button can be overlaid later. Do not render lorem-ipsum or placeholder text. When a reference image is supplied, keep its product/brand faithfully.',
                'user_prompt' => 'Design a store banner based on this brief: {{brief}}. Make it visually striking, on-brand, and suitable as a wide promotional banner.',
                'version' => 1,
                'is_active' => true,
            ],
        );
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
        // Alternative provider, OFF by default. The published Seedream 5.0 Lite price
        // ($0.035/image) so activating it just works; the admin can adjust it.
        foreach (self::SEEDREAM_MODELS as $modelId => $label) {
            $this->seedModel(AiOperation::KEY_TRY_ON_GENERATION, $modelId, $label, costHint: 35_000, unit: AiModel::UNIT_PER_IMAGE, provider: AiModel::PROVIDER_BYTEPLUS, isActive: false);
        }

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
        string $provider = AiModel::PROVIDER_OPENROUTER,
        bool $isActive = true,
    ): void {
        AiModel::updateOrCreate(
            ['operation_key' => $operationKey, 'model_id' => $modelId],
            [
                'provider' => $provider,
                'label' => $label,
                'is_default' => $isDefault,
                'is_fallback' => $isFallback,
                'cost_hint_micro_usd' => $costHint,
                'cost_unit' => $unit,
                'is_active' => $isActive,
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
