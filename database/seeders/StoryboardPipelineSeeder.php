<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Prompt;
use Illuminate\Database\Seeder;

/**
 * StoryboardPipelineSeeder — DB defaults for the storyboard pre-production pipeline.
 *
 * Each step is an ai_operations row (admin-editable model/prompt/params/schema/fallback) + a
 * scope=global prompt + its allow-listed models — exactly like the scan/try-on/banner operations,
 * so the Super-Admin tunes every step from the existing AI screens without a redeploy. The text
 * steps enforce strict JSON via input_schema; the frame-image step is an image-generation op.
 */
class StoryboardPipelineSeeder extends Seeder
{
    // === CONSTANTS (admin-editable DEFAULTS) ===
    private const TEXT_MODEL = 'google/gemini-2.5-flash';
    private const TEXT_FALLBACK = 'openai/gpt-4o-mini';

    // gemini-3.1-flash-image returns 400 on OpenRouter; gemini-2.5-flash-image is the proven one.
    private const IMAGE_MODEL = 'google/gemini-2.5-flash-image';

    private const TEXT_PARAMS = ['temperature' => 0.6, 'top_p' => 0.95, 'max_tokens' => 8000];
    private const IMAGE_QUALITY = 'high';
    private const IMAGE_ASPECT = '16:9';

    public function run(): void
    {
        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_READ_IDEA,
            'Storyboard · Read Idea',
            'You are a senior film pre-production analyst. Read the raw story idea and return ONLY a JSON object matching the schema: the cleaned story, the main intent, important elements, any @reference tags used, what information is missing, and the creative direction. Never invent facts; use empty/null where unknown.',
            "Story idea:\n{{story_idea}}\n\nAvailable reference tags: {{reference_tags}}.\nReturn strict JSON for the schema.",
            $this->readIdeaSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_GENRE,
            'Storyboard · Genre Alignment',
            'You are a cinematic art director. Given the story and the target genre, define the visual language: genre, emotional tone, camera language, lighting, color palette, typography, editing pace, and negative rules. Return ONLY JSON matching the schema.',
            "Target genre: {{genre}}.\nClean story: {{clean_story}}.\nReturn strict JSON for the schema.",
            $this->genreSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_CHARACTERS,
            'Storyboard · Characters & Assets',
            'You are a continuity supervisor. From the story, identify every character, location, and asset (product/logo/prop). For each give a name, its @tag if referenced, a description, and continuity rules; bind @tags to the referenced uploads. Return ONLY JSON matching the schema.',
            "Clean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nReference tags available: {{reference_tags}}.\nReturn strict JSON for the schema.",
            $this->charactersSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_VISUAL_BIBLE,
            'Storyboard · Visual Bible',
            'You are the film\'s visual-bible author. Produce the single global visual style that EVERY frame must follow: global_style, camera, lighting, color_palette, mood, typography, continuity_rules, and a reusable negative_prompt. Keep EVERY field concise — one or two sentences; the negative_prompt must be a SHORT comma-separated list of at most 12 distinct terms, never an exhaustive or repeated list. Return ONLY JSON matching the schema.',
            "Clean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nCharacters: {{characters}}.\nReturn strict JSON for the schema.",
            $this->visualBibleSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN,
            'Storyboard · Scene Breakdown',
            'You are a storyboard director. Break the story into EXACTLY {{frame_count}} frames, one per {{frame_interval}} seconds, covering 0..{{duration}}s. For each frame give the timing, description, camera angle, composition, action, which characters and @reference tags appear, any on-screen text, and a COMPLETE image_prompt that already embeds the visual bible so every frame looks like the same film — plus a per-frame negative_prompt (a SHORT list of at most 10 distinct terms, never repeated). Use @reference tags verbatim. Return ONLY JSON matching the schema.',
            "Duration: {{duration}}s, one frame per {{frame_interval}}s => {{frame_count}} frames. Aspect ratio {{aspect_ratio}}.\nClean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nCharacters: {{characters}}.\nVisual bible: {{visual_bible}}.\nReference tags available: {{reference_tags}}.\nReturn strict JSON for the schema with exactly {{frame_count}} frames.",
            $this->sceneBreakdownSchema(),
        );

        $this->seedFrameImageStep();
        $this->seedClipStep();
    }

    /** Seed the per-frame VIDEO clip step (image-to-video via BytePlus/Seedance). INACTIVE-ready:
     *  the model id is volatile so verify it + set a BytePlus key before generating clips. */
    private function seedClipStep(): void
    {
        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_STORYBOARD_CLIP],
            [
                'label' => 'Storyboard · Video Clip',
                'default_model' => 'dreamina-seedance-2-0-260128',
                'fallback_model' => null,
                'image_quality' => null,
                'aspect_ratio' => null,
                'params' => ['resolution' => '720p', 'duration_seconds' => 3, 'ratio' => 'adaptive'],
                'input_schema' => null,
                'retention_days' => null,
                'estimated_cost_micro_usd' => 200_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_STORYBOARD_CLIP, 'dreamina-seedance-2-0-260128', 'Seedance 2.0 (BytePlus)', isDefault: true, unit: AiModel::UNIT_PER_IMAGE, costHint: 200_000, provider: AiModel::PROVIDER_BYTEPLUS);

        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_CLIP,
            'Animate the storyboard frame into a short, smooth cinematic video clip. Keep the subject, style and composition; add natural, subtle motion and a gentle camera move consistent with the shot.',
            '{{image_prompt}} {{motion}}',
        );
    }

    /** Seed one text step: the operation, its allow-listed models, and its global prompt. */
    private function seedTextStep(string $key, string $label, string $system, string $user, array $schema): void
    {
        AiOperation::updateOrCreate(
            ['operation_key' => $key],
            [
                'label' => $label,
                'default_model' => self::TEXT_MODEL,
                'fallback_model' => self::TEXT_FALLBACK,
                'image_quality' => null,
                'aspect_ratio' => null,
                'params' => self::TEXT_PARAMS,
                'input_schema' => $schema,
                'retention_days' => null,
                'estimated_cost_micro_usd' => 4_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel($key, self::TEXT_MODEL, 'Gemini 2.5 Flash', isDefault: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: 3_000);
        $this->seedModel($key, self::TEXT_FALLBACK, 'GPT-4o mini', isFallback: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: 2_000);

        $this->seedPrompt($key, $system, $user);
    }

    /** Seed the frame-image generation step (an image operation, like banner/try-on). */
    private function seedFrameImageStep(): void
    {
        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_STORYBOARD_FRAME_IMAGE],
            [
                'label' => 'Storyboard · Frame Image',
                'default_model' => self::IMAGE_MODEL,
                'fallback_model' => null,
                'image_quality' => self::IMAGE_QUALITY,
                'aspect_ratio' => self::IMAGE_ASPECT,
                'params' => ['temperature' => 0.7, 'top_p' => 0.95],
                'input_schema' => null,
                'retention_days' => null,
                'estimated_cost_micro_usd' => 60_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_STORYBOARD_FRAME_IMAGE, self::IMAGE_MODEL, 'Gemini 2.5 Flash Image', isDefault: true, unit: AiModel::UNIT_PER_IMAGE, costHint: 40_000);

        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
            'Generate a single, high-quality cinematic storyboard frame image. Follow the prompt exactly and keep characters, location, lighting and overall style consistent with the rest of the storyboard.',
            '{{image_prompt}}',
        );
    }

    private function seedModel(string $key, string $modelId, string $label, bool $isDefault = false, bool $isFallback = false, ?string $unit = null, ?int $costHint = null, string $provider = AiModel::PROVIDER_OPENROUTER): void
    {
        AiModel::updateOrCreate(
            ['operation_key' => $key, 'model_id' => $modelId],
            [
                'provider' => $provider,
                'label' => $label,
                'is_default' => $isDefault,
                'is_fallback' => $isFallback,
                'cost_hint_micro_usd' => $costHint,
                'cost_unit' => $unit,
                'is_active' => true,
            ],
        );
    }

    private function seedPrompt(string $key, string $system, string $user): void
    {
        Prompt::updateOrCreate(
            [
                'scope' => Prompt::SCOPE_GLOBAL,
                'operation_key' => $key,
                'product_type' => null,
                'account_id' => null,
                'site_id' => null,
            ],
            [
                'system_prompt' => $system,
                'user_prompt' => $user,
                'version' => 1,
                'is_active' => true,
            ],
        );
    }

    private function readIdeaSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'clean_story_summary' => ['type' => 'string'],
                'main_intent' => ['type' => 'string'],
                'important_elements' => ['type' => 'array', 'items' => ['type' => 'string']],
                'reference_tags_found' => ['type' => 'array', 'items' => ['type' => 'string']],
                'missing_information' => ['type' => 'array', 'items' => ['type' => 'string']],
                'creative_direction' => ['type' => 'string'],
            ],
            'required' => ['clean_story_summary', 'main_intent', 'creative_direction'],
        ];
    }

    private function genreSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'genre' => ['type' => 'string'],
                'emotional_tone' => ['type' => 'string'],
                'camera_language' => ['type' => 'string'],
                'lighting' => ['type' => 'string'],
                'color_palette' => ['type' => 'string'],
                'typography' => ['type' => 'string'],
                'editing_pace' => ['type' => 'string'],
                'negative_rules' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['genre', 'emotional_tone'],
        ];
    }

    private function charactersSchema(): array
    {
        $named = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => ['string', 'null']],
                'description' => ['type' => 'string'],
            ],
            'required' => ['name', 'description'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'characters' => ['type' => 'array', 'items' => $this->withProps($named, [
                    'outfit' => ['type' => ['string', 'null']],
                    'continuity_rules' => ['type' => ['string', 'null']],
                ])],
                'locations' => ['type' => 'array', 'items' => $named],
                'assets' => ['type' => 'array', 'items' => $this->withProps($named, [
                    'must_be_exact' => ['type' => 'boolean'],
                ])],
            ],
            'required' => ['characters'],
        ];
    }

    private function visualBibleSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'global_style' => ['type' => 'string'],
                'camera' => ['type' => 'string'],
                'lighting' => ['type' => 'string'],
                'color_palette' => ['type' => 'string'],
                'mood' => ['type' => 'string'],
                'typography' => ['type' => 'string'],
                'continuity_rules' => ['type' => 'string'],
                'negative_prompt' => ['type' => 'string'],
            ],
            'required' => ['global_style', 'negative_prompt'],
        ];
    }

    private function sceneBreakdownSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'frames' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'frame_number' => ['type' => 'integer'],
                            'start_second' => ['type' => 'integer'],
                            'end_second' => ['type' => 'integer'],
                            'description' => ['type' => 'string'],
                            'camera_angle' => ['type' => ['string', 'null']],
                            'composition' => ['type' => ['string', 'null']],
                            'action' => ['type' => ['string', 'null']],
                            'characters' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'reference_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'text_overlay' => ['type' => ['string', 'null']],
                            'image_prompt' => ['type' => 'string'],
                            'negative_prompt' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['frame_number', 'start_second', 'end_second', 'description', 'image_prompt'],
                    ],
                ],
            ],
            'required' => ['frames'],
        ];
    }

    /** Merge extra properties onto a base object schema (kept required list intact). */
    private function withProps(array $base, array $extra): array
    {
        $base['properties'] = array_merge($base['properties'], $extra);

        return $base;
    }
}
