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
    // The strongest live Gemini text tier drives every PLANNING step (script, characters, shot
    // list). The -preview suffix is volatile on OpenRouter — verify against /models if calls start
    // failing; the fallback covers a retirement per-request.
    private const TEXT_MODEL = 'google/gemini-3.1-pro-preview';
    private const TEXT_MODEL_LABEL = 'Gemini 3.1 Pro (preview)';
    private const TEXT_FALLBACK = 'google/gemini-3.5-flash';
    private const TEXT_FALLBACK_LABEL = 'Gemini 3.5 Flash';

    // gemini-3.1-flash-image returns 400 on OpenRouter; gemini-2.5-flash-image is the proven one.
    // Frame images deliberately stay on the FAST model; the pro image model is catalogued as a
    // NON-default option the admin can switch to from the pipeline settings.
    private const IMAGE_MODEL = 'google/gemini-2.5-flash-image';
    private const IMAGE_MODEL_ALT = 'google/gemini-3-pro-image';
    private const IMAGE_MODEL_ALT_LABEL = 'Gemini 3 Pro Image (Nano Banana Pro)';

    private const TEXT_PARAMS = ['temperature' => 0.6, 'top_p' => 0.95, 'max_tokens' => 12000];
    private const IMAGE_QUALITY = 'high';
    private const IMAGE_ASPECT = '16:9';

    // Cost hints (micro-USD). Pro-tier text runs ~5-7x the old flash pricing.
    private const TEXT_STEP_EST = 25_000;
    private const TEXT_MODEL_HINT = 12_000;   // per 1k tokens
    private const TEXT_FALLBACK_HINT = 9_000; // per 1k tokens
    private const IMPROVE_EST = 5_000;
    private const IMAGE_ALT_HINT = 120_000;   // per image (estimate)

    public function run(): void
    {
        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_READ_IDEA,
            'Storyboard · Read Idea',
            'You are a senior film development executive and story analyst. Distill the raw story idea into a production-ready brief: a cleaned, coherent retelling of the story that keeps EVERY fact the author gave (fix only clarity — resolve nothing by invention); the main intent (what this film must make the viewer feel or do); the concrete elements that MUST appear on screen; every @reference tag the author used, verbatim; what information is genuinely missing for production; and a creative direction — one tight paragraph naming the protagonist and what they want, the stakes, the emotional arc from first shot to last, and the single image the film should be remembered by. Ground every field strictly in the author\'s text; NEVER invent characters, events, brands or facts — use empty strings/arrays or null where unknown. The story may arrive in any language: understand it natively, write your output in clear English, and preserve proper names exactly as written. Return ONLY a JSON object matching the schema.',
            "Story idea:\n{{story_idea}}\n\nAvailable reference tags: {{reference_tags}}.\nReturn strict JSON for the schema.",
            $this->readIdeaSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_GENRE,
            'Storyboard · Genre Alignment',
            'You are a veteran cinematic art director defining a film\'s visual language. Given the story and the target genre, commit to ONE precise, filmable look: the genre (with a sub-genre if it sharpens the look); the emotional tone across the arc; the camera language as concrete craft choices (lens focal lengths in mm, framing habits, movement style — e.g. "35mm anamorphic, low angles, slow push-ins"); the lighting (key style, contrast feel, practical sources — e.g. "hard single-source key, deep shadows, sodium-vapor practicals"); a concrete color palette naming 3-5 actual colors and where each lives (skin, sets, wardrobe, highlights); typography for any on-screen text (family style, weight, placement); the editing pace (cut rhythm per scene type); and negative rules — the visual clichés and styles this specific film must NEVER use. Be specific enough that two artists working apart would produce matching frames; never write vague praise like "cinematic" or "beautiful" without stating HOW. Return ONLY a JSON object matching the schema.',
            "Target genre: {{genre}}.\nClean story: {{clean_story}}.\nReturn strict JSON for the schema.",
            $this->genreSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_CHARACTERS,
            'Storyboard · Characters & Assets',
            'You are a film continuity supervisor building the character and asset bible. From the story, identify EVERY character, location, and asset (product, logo, prop). Describe each character so an image model with NO memory can repaint them identically in every frame: apparent age, build, skin tone, face shape, hair (color, length, style), eyes, facial hair, and 1-2 distinguishing marks; give the outfit as an exact wardrobe list (garments, colors, fabrics, shoes, accessories) that stays IDENTICAL across the film unless the story changes it; and continuity_rules stating what must never change. For each location: time of day, architecture or terrain, set dressing, light sources. For each asset: its exact appearance; set must_be_exact=true for real products and logos that may not be altered. When an element has an uploaded reference image, bind it: set its tag to the matching @tag from the available list, verbatim — the reference image is ground truth and your description must defer to it. Never invent characters or brands the story does not contain. Return ONLY a JSON object matching the schema.',
            "Clean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nReference tags available: {{reference_tags}}.\nReturn strict JSON for the schema.",
            $this->charactersSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_VISUAL_BIBLE,
            'Storyboard · Visual Bible',
            'You are the film\'s visual-bible author. Compress the story, genre profile and character bible into ONE binding style contract that every frame prompt will restate: global_style (medium, rendering style and film-stock/grade feel in one sentence); camera (default lens, framing and movement grammar); lighting (key style, contrast, color of light); color_palette (the 3-5 committed colors and their roles); mood (the emotional constant); typography (for any on-screen text); continuity_rules (the specific things that must stay pixel-consistent across frames: character looks, wardrobe, props, environment logic); and one reusable negative_prompt. Keep EVERY field to one or two tight, concrete sentences — this text is embedded into every frame prompt, so every word must earn its place. The negative_prompt must be a SHORT comma-separated list of at most 12 distinct terms (e.g. "blurry, deformed hands, extra fingers, watermark"), never an exhaustive or repeated list. Return ONLY a JSON object matching the schema.',
            "Clean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nCharacters: {{characters}}.\nReturn strict JSON for the schema.",
            $this->visualBibleSchema(),
        );

        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN,
            'Storyboard · Scene Breakdown',
            'You are an award-winning storyboard director and prompt engineer for cinematic image models. Break the story into EXACTLY {{frame_count}} frames, one per {{frame_interval}} seconds, covering 0..{{duration}}s with no gaps: together they must tell the COMPLETE story with a clear setup, development and payoff, each frame a NEW story beat — never a redundant variation of the previous one. For every frame provide: the timing; a description of what happens (for humans); camera_angle and composition in real shot grammar (shot size, angle, lens in mm, depth of field, subject placement); the action; which characters appear; which @reference tags appear (verbatim from the available list); any text_overlay in the story\'s ORIGINAL language (else null); a motion phrase — one short English sentence of camera + subject movement for animating this frame into video (e.g. "slow push-in as she turns toward the window"); and the image_prompt. The image_prompt is the product: write it as a COMPLETE, SELF-CONTAINED English prompt for an image model that has NO memory of other frames — restate in full the appearance and exact wardrobe of every character in the shot (from the character bible), the location, the lighting, the camera and lens, and the visual bible\'s global style and palette, so all {{frame_count}} images read as consecutive stills from the same film. ALWAYS write image_prompt in English regardless of the story\'s language, keep @reference tags verbatim inside it, and never reference other frames ("same as before" is forbidden — the image model cannot see them). Add a per-frame negative_prompt: a SHORT comma-separated list of at most 10 distinct terms. Return ONLY a JSON object matching the schema with exactly {{frame_count}} frames.',
            "Duration: {{duration}}s, one frame per {{frame_interval}}s => {{frame_count}} frames. Aspect ratio {{aspect_ratio}}.\nClean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nCharacters: {{characters}}.\nVisual bible: {{visual_bible}}.\nReference tags available: {{reference_tags}}.\nReturn strict JSON for the schema with exactly {{frame_count}} frames.",
            $this->sceneBreakdownSchema(),
        );

        $this->seedFrameImageStep();
        $this->seedClipStep();
        $this->seedImprovePromptStep();
    }

    /**
     * Seed the on-demand "improve prompt" operation: an LLM rewrites a single frame's image_prompt
     * from a plain instruction ("give the king white hair"). Public so a data migration can seed it
     * onto existing installs WITHOUT re-running the whole pipeline seed (which would reset admin edits).
     */
    public function seedImprovePromptStep(): void
    {
        $this->clearModelFlags(AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT);

        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT],
            [
                'label' => 'Storyboard · Improve Prompt',
                'default_model' => self::TEXT_MODEL,
                'fallback_model' => self::TEXT_FALLBACK,
                'image_quality' => null,
                'aspect_ratio' => null,
                'params' => ['temperature' => 0.5, 'top_p' => 0.95, 'max_tokens' => 2000],
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['improved_prompt' => ['type' => 'string']],
                    'required' => ['improved_prompt'],
                ],
                'retention_days' => null,
                'estimated_cost_micro_usd' => self::IMPROVE_EST,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT, self::TEXT_MODEL, self::TEXT_MODEL_LABEL, isDefault: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_MODEL_HINT);
        $this->seedModel(AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT, self::TEXT_FALLBACK, self::TEXT_FALLBACK_LABEL, isFallback: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_FALLBACK_HINT);

        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT,
            'You are a surgical prompt editor for a cinematic image generator. Rewrite the ORIGINAL image prompt to apply the requested change — and change NOTHING else. Keep the wording, order, style descriptors, camera and lighting language, palette and overall length as close to the original as possible; preserve every @reference tag EXACTLY as written; keep it one self-contained English image prompt. If the change conflicts with part of the original, update only that part. Never add characters, props or style directions that were not requested. Return ONLY JSON: {"improved_prompt": "..."}.',
            "Original image prompt:\n{{original}}\n\nRequested change:\n{{instruction}}\n\nReturn strict JSON with the improved prompt.",
        );
    }

    /** Seed the per-frame VIDEO clip step (image-to-video via BytePlus/Seedance). INACTIVE-ready:
     *  the model id is volatile so verify it + set a BytePlus key before generating clips. */
    private function seedClipStep(): void
    {
        $this->clearModelFlags(AiOperation::KEY_STORYBOARD_CLIP);

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
        // A NON-default AtlasCloud option so an admin can switch the clip step to AtlasCloud from the
        // pipeline settings; BytePlus Seedance stays the default. The model id is volatile — verify it.
        $this->seedModel(AiOperation::KEY_STORYBOARD_CLIP, 'bytedance/seedance-2.0/reference-to-video', 'Seedance 2.0 (AtlasCloud)', unit: AiModel::UNIT_PER_IMAGE, costHint: 200_000, provider: AiModel::PROVIDER_ATLASCLOUD);

        // Video providers take ONE prompt string (no system message), so everything the clip needs
        // lives in the user template. {{motion}} is the frame's motion_prompt (camera + subject move).
        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_CLIP,
            null,
            "{{image_prompt}}\n\nAnimate this exact frame into a short cinematic clip. Camera and subject motion: {{motion}}. Keep the characters, wardrobe, lighting, composition and art style identical to the source image; motion must be smooth, subtle and physically plausible — no morphing, no flicker, no new elements or on-screen text.",
        );
    }

    /** Seed one text step: the operation, its allow-listed models, and its global prompt. */
    private function seedTextStep(string $key, string $label, string $system, string $user, array $schema): void
    {
        $this->clearModelFlags($key);

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
                'estimated_cost_micro_usd' => self::TEXT_STEP_EST,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel($key, self::TEXT_MODEL, self::TEXT_MODEL_LABEL, isDefault: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_MODEL_HINT);
        $this->seedModel($key, self::TEXT_FALLBACK, self::TEXT_FALLBACK_LABEL, isFallback: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_FALLBACK_HINT);

        $this->seedPrompt($key, $system, $user);
    }

    /** Seed the frame-image generation step (an image operation, like banner/try-on). */
    private function seedFrameImageStep(): void
    {
        $this->clearModelFlags(AiOperation::KEY_STORYBOARD_FRAME_IMAGE);

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
        // The strongest image tier, catalogued as a NON-default choice (admin switches in settings).
        $this->seedModel(AiOperation::KEY_STORYBOARD_FRAME_IMAGE, self::IMAGE_MODEL_ALT, self::IMAGE_MODEL_ALT_LABEL, unit: AiModel::UNIT_PER_IMAGE, costHint: self::IMAGE_ALT_HINT);

        // This system prompt IS applied at generation time (prepended to the frame's own
        // image_prompt) — it must stay placeholder-free: it is substituted with no vars, so any
        // {{token}} would reach the image model literally.
        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
            'Create ONE cinematic still frame of a film. Follow the prompt EXACTLY: the characters\' faces, hair, wardrobe and colors, the location, the camera, lens, lighting, composition and the stated art style — and keep them consistent with any attached reference images, which are ground truth for identity and design. When an existing version of this frame is attached, treat it as the shot to EDIT: preserve its composition, characters and style and change only what the prompt asks. Deliver a clean full-bleed frame: no borders, watermarks, signatures or UI, and no text unless the prompt explicitly specifies on-screen text.',
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

    /**
     * Reset default/fallback flags on an operation's catalogued models BEFORE seeding the new
     * ones — otherwise superseded rows (e.g. the old flash default) keep stale flags and the
     * resolver can pick a random one of two flagged fallbacks. Rows stay catalogued (allow-list).
     */
    private function clearModelFlags(string $key): void
    {
        AiModel::query()
            ->where('operation_key', $key)
            ->update(['is_default' => false, 'is_fallback' => false]);
    }

    private function seedPrompt(string $key, ?string $system, string $user): void
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
                            'motion' => ['type' => ['string', 'null']],
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
