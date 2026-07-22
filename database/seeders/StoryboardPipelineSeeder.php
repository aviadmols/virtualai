<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\StoryboardAsset;
use App\Models\StoryboardProject;
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

    // Frame images: the DEFAULT is the EDIT-capable Nano Banana (fal) — every chained frame is
    // generated FROM the previous frame's image + the @tag references, which is what carries
    // cross-frame consistency. The anchor-less FIRST frame (which sets the whole film's look)
    // upgrades to Nano Banana Pro via the `first_frame_model` param. Krea 2 Turbo stays
    // catalogued as a cheap NON-default option (it is text-to-image and blind to references).
    private const IMAGE_MODEL = 'fal-ai/nano-banana/edit';

    private const IMAGE_MODEL_LABEL = 'Nano Banana Edit (fal.ai)';

    private const IMAGE_PROVIDER = AiModel::PROVIDER_FAL;

    private const IMAGE_MODEL_HINT = 40_000; // per image (estimate)

    private const IMAGE_MODEL_KREA = 'fal-ai/krea-2/turbo';

    private const IMAGE_MODEL_KREA_LABEL = 'Krea 2 Turbo (fal.ai)';

    private const IMAGE_MODEL_KREA_HINT = 15_000;

    private const IMAGE_MODEL_GEMINI = 'google/gemini-2.5-flash-image';

    // The look-setting FIRST-frame model (params.first_frame_model): the premium image tier.
    private const IMAGE_FIRST_MODEL = 'google/gemini-3-pro-image';

    private const IMAGE_FIRST_LABEL = 'Gemini 3 Pro Image (Nano Banana Pro)';

    // fal VIDEO options for the clip step (NON-default; ids verified in the live fal catalog).
    private const CLIP_FAL_KLING = 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video';

    private const CLIP_FAL_VEO = 'fal-ai/veo3.1/fast/image-to-video';

    private const TEXT_PARAMS = ['temperature' => 0.6, 'top_p' => 0.95, 'max_tokens' => 12000];

    // The Story Director emits FIVE sections in one output — more room, steadier temperature.
    private const DIRECTOR_PARAMS = ['temperature' => 0.5, 'top_p' => 0.95, 'max_tokens' => 16000];

    private const IMAGE_QUALITY = 'high';

    private const IMAGE_ASPECT = '16:9';

    // Cost hints (micro-USD). Pro-tier text runs ~5-7x the old flash pricing.
    private const TEXT_STEP_EST = 25_000;

    private const DIRECTOR_STEP_EST = 45_000;

    private const TEXT_MODEL_HINT = 12_000;   // per 1k tokens

    private const TEXT_FALLBACK_HINT = 9_000; // per 1k tokens

    private const IMPROVE_EST = 5_000;

    private const IMAGE_ALT_HINT = 120_000;   // per image (estimate)

    public function run(): void
    {
        $this->seedStoryDirectorStep();
        $this->seedSceneBreakdownStep();
        $this->seedFrameImageStep();
        $this->seedClipStep();
        $this->seedImprovePromptStep();
        $this->seedAssetAnalysisStep();
        $this->seedVideoDirectorStep();
    }

    /**
     * Seed the STORY DIRECTOR — the single planning call that locks the whole plan: story bible
     * (with content_type and relationship facts), genre profile, character/asset bible (reference
     * identity locked, wardrobe designed), visual bible, and the shot timing. One call replaces the
     * four separate read-idea/genre/characters/visual-bible steps — half the cost, zero drift
     * between stages. Public so a data migration can seed JUST this step.
     */
    public function seedStoryDirectorStep(): void
    {
        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_STORY_DIRECTOR,
            'Storyboard · Story Director',
            'You are the film\'s STORY DIRECTOR — development executive, art director, character designer, continuity supervisor and editor in ONE pass. Lock EVERY planning decision for this short film in a single output; later stages obey it verbatim and may not reinterpret it. Ground everything strictly in the author\'s text and the reference analyses; NEVER invent characters, events, brands or facts. The story may arrive in any language: understand it natively, write in clear English, preserve proper names exactly. Fill these sections: '
            .'STORY — a cleaned retelling keeping EVERY fact the author gave (fix clarity only); main_intent; important_elements that must appear on screen; reference_tags_found verbatim; missing_information; creative_direction (protagonist and want, stakes, the emotional arc first shot to last, the single image the film is remembered by); and content_type: "complete_micro_story" (the DEFAULT — the outcome must be SHOWN on screen) or "trailer" ONLY if the author explicitly asked for a teaser/trailer. RELATIONSHIPS ARE FACTS: state each bond exactly as the author wrote it (siblings, friends, parent and child); NEVER romanticize a bond the author did not declare romantic; with child characters use family language — "protective sibling bond", never "beloved" or "romantic devotion". '
            .'GENRE_PROFILE — commit to ONE precise filmable look: a genre label that fits the story\'s ACTUAL relationships and ages (two children => "Family Adventure Creature Thriller", never "Romantic ..."); emotional_tone across the arc; camera_language as concrete craft (lens mm, framing habits, movement style); lighting (key style, contrast, practicals); color_palette naming 3-5 actual colors and where each lives; typography; editing_pace that fits the format; negative_rules — visual clichés this film must NEVER use. Be specific enough that two artists apart would produce matching frames. '
            .'CHARACTERS — every character, location and asset. For a @tag-bound character IDENTITY IS THE REFERENCE IMAGE: set tag, and set identity_lock to a SHORT phrase containing ONLY features the reference analysis explicitly states (copy, never embellish) — if the analysis does not mention a feature (freckles, eye color), OMIT it entirely rather than guess. WARDROBE IS YOURS TO DESIGN: the photo\'s clothing is not binding — dress the character as their ROLE demands and state the FINAL wardrobe in story_wardrobe as an exact garment list; that list stays IDENTICAL across the film. Reference images define identity only — never their background, pose, lighting or angle. Give each character scale_reference, signature_prop, default_expression, movement_style, continuity_rules. For each location: time of day, terrain/architecture, set dressing, light sources. For each asset: exact appearance; must_be_exact=true for real products/logos. '
            .'VISUAL_BIBLE — the binding style contract, one or two tight sentences per field: global_style, camera, lighting, color_palette, mood, typography, continuity_rules, and one reusable negative_prompt (a SHORT comma list of at most 12 distinct terms). '
            .'SHOT_TIMING — YOU decide the cut list. One shot = ONE continuous camera setup or movement; every cut or new camera move starts a NEW shot. Return between {{min_shots}} and {{max_shots}} shots whose duration_seconds sum EXACTLY to {{duration}}; each shot between 1 and {{max_shot_seconds}} seconds. Vary lengths to serve the drama — a climax may breathe longer than a setup beat. For each shot give camera_movement as ONE concrete executable move (e.g. "slow push-in from wide to medium on the door", "static low-angle wide", "handheld tracking left with the runner") — it will drive both the still frame\'s composition and the clip\'s animation, and it must obey the genre profile\'s negative_rules; shot_purpose names the beat (setup / escalation / climax / resolution). This timing is LOCKED — the whole film is cut to it. Return ONLY a JSON object matching the schema.',
            "Story idea:\n{{story_idea}}\n\nTarget genre: {{genre}}.\nFormat: a {{duration}}-second film told in {{min_shots}}-{{max_shots}} shots (you choose the cut list), aspect ratio {{aspect_ratio}}.\nAvailable reference tags: {{reference_tags}}.\nReference image analyses (identity ground truth from the actual uploads):\n{{reference_descriptions}}\nReturn strict JSON for the schema.",
            $this->storyDirectorSchema(),
            params: self::DIRECTOR_PARAMS,
            estimate: self::DIRECTOR_STEP_EST,
        );
    }

    /**
     * Seed the SCENE BREAKDOWN (the storyboard director) — the second and final planning call: it
     * receives the LOCKED plan and returns per-frame SCENE beats only. Identity/wardrobe/style are
     * NOT restated by the model — StoryboardPromptComposer appends the locked blocks in code, and
     * frame timing is stamped from the locked plan. Public so a data migration can seed JUST this step.
     */
    public function seedSceneBreakdownStep(): void
    {
        $this->seedTextStep(
            AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN,
            'Storyboard · Scene Breakdown',
            'You are an award-winning STORYBOARD DIRECTOR turning a LOCKED plan into frames. Everything you receive — story, genre profile, character bible, visual bible, shot timing, content type — is already decided and immutable: never re-time, re-dress, re-design or re-genre anything. Break the story into EXACTLY {{frame_count}} frames matching the locked shot_timing one-to-one (frame N is cut to slot N; the timing is not yours to change). Slot N\'s camera_movement is BINDING: frame N\'s camera_angle and composition stage that exact move\'s starting position, and its motion field EXECUTES that exact move — you may add subject motion on top, never a contradicting camera move. Together the frames tell the arc the content_type demands: for complete_micro_story the final frame SHOWS the resolved outcome (the rescue succeeds, the threat is left behind, safety is visible) — never end on an unresolved standoff; for trailer a deliberate cliffhanger final beat is allowed. Each frame is a NEW story beat — never a redundant variation of the previous one. For every frame provide: description (for humans); camera_angle and composition in real shot grammar (shot size, angle, lens in mm, depth of field, subject placement) — camera work MUST obey the genre profile\'s negative_rules: where shaky-cam blur is banned, write "controlled handheld tension, subject clearly visible, no motion blur", never "erratic shake" or blur-inducing whip pans; action; characters — the EXACT names from the character bible; reference_tags verbatim from the available list; text_overlay in the story\'s ORIGINAL language (else null); motion — one short English sentence of camera + subject movement for animating this frame (it must also obey the negative_rules); scene_prompt; negative_prompt. The scene_prompt is THIS FRAME\'S BEAT ONLY: 2-4 tight English sentences of what the camera sees — the action and staging, the characters\' expressions and body language, the location\'s look in this shot, the light at this moment, and the camera/lens. DO NOT restate character identities, faces, wardrobe, the global style or the palette — the system appends the LOCKED character and style blocks verbatim after your text in every frame, so write a scene that reads naturally when those blocks follow. Refer to characters by their bible name (keep @reference tags verbatim where they appear) and never reference other frames ("same as before" is forbidden). negative_prompt: a SHORT comma-separated list of at most 10 distinct terms, without repeating the visual bible\'s reusable list. Return ONLY a JSON object matching the schema with exactly {{frame_count}} frames.',
            "LOCKED format: {{duration}}s, {{frame_count}} frames, aspect ratio {{aspect_ratio}}. Content type: {{content_type}}.\nLOCKED shot timing (frame N = slot N, immutable):\n{{shot_timing}}\nClean story: {{clean_story}}.\nGenre profile: {{genre_profile}}.\nCharacter bible: {{characters}}.\nVisual bible: {{visual_bible}}.\nReference tags available: {{reference_tags}}.\nReference image analyses (ground truth from the actual uploads):\n{{reference_descriptions}}\nReturn strict JSON for the schema with exactly {{frame_count}} frames.",
            $this->sceneBreakdownSchema(),
        );
    }

    /**
     * Seed the DIRECTOR operation: before a one-call ("reference") video generation, a multimodal
     * model receives the generated frame images IN SHOT ORDER plus the storyboard data and
     * composes the final film prompt — a bracketed timed shot list with motivated transitions and
     * locked spatial continuity. Public so a data migration can seed JUST this step.
     */
    public function seedVideoDirectorStep(): void
    {
        $this->clearModelFlags(AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR);

        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR],
            [
                'label' => 'Storyboard · Film Director',
                'default_model' => self::TEXT_MODEL,
                'fallback_model' => self::TEXT_FALLBACK,
                'image_quality' => null,
                'aspect_ratio' => null,
                'params' => ['temperature' => 0.4, 'top_p' => 0.9, 'max_tokens' => 3000],
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['video_prompt' => ['type' => 'string']],
                    'required' => ['video_prompt'],
                ],
                'retention_days' => null,
                'estimated_cost_micro_usd' => self::TEXT_STEP_EST,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR, self::TEXT_MODEL, self::TEXT_MODEL_LABEL, isDefault: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_MODEL_HINT);
        $this->seedModel(AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR, self::TEXT_FALLBACK, self::TEXT_FALLBACK_LABEL, isFallback: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_FALLBACK_HINT);

        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR,
            'You are the film\'s DIRECTOR, composing the single prompt a one-call multi-shot AI video model will follow to render the ENTIRE film. The attached images ARE the storyboard frames, in shot order — image 1 is shot 1\'s visual ground truth, image 2 is shot 2\'s, and so on. STUDY them before writing: who stands where, which way each character faces, screen direction, lighting and palette — your prompt must keep all of it consistent from shot to shot. Write ONE cinematic prompt that: (1) opens with a one-line film summary and the total duration; (2) lists EVERY shot as a bracketed timed line in the form [00:00-00:03], covering 0 to {{total_seconds}} seconds with no gaps, one line per storyboard shot in order, restating in each line the characters present (identity and wardrobe), the location, the key action and the camera (shot size, angle, movement); (3) names an explicit, MOTIVATED camera transition into every shot (cut on action, match cut, whip pan to — never an unexplained jump); (4) keeps spatial continuity and consistent screen direction across cuts — characters never teleport or swap sides between shots unless a shot shows the move; (5) places each quoted dialogue line inside its own shot\'s time window, spoken aloud and lip-synced; (6) where the target video model documents reference tokens (character1..characterN bound to the attached images in order), uses them to name the people; (7) is written in clear cinematic English and stays UNDER {{max_chars}} characters in total. Return ONLY JSON: {"video_prompt": "..."}.',
            "Film: {{story}}\nTotal duration: {{total_seconds}} seconds across {{shot_count}} shots. Aspect ratio {{aspect_ratio}}, resolution {{resolution}}.\nVisual style: {{global_style}}\nContinuity rules: {{continuity_rules}}\nCharacters (keep identical throughout): {{characters}}\nReference image analyses (ground truth): {{reference_analyses}}\nStoryboard shots (in order, with target timings):\n{{shot_list}}\n\nCompose the final video prompt now. Return strict JSON with video_prompt (under {{max_chars}} characters).",
        );
    }

    /**
     * Seed the VISION reference-analysis operation: a multimodal model describes each uploaded
     * reference image (the ground truth behind a @tag) so planned characters match the uploads.
     * Public so a data migration can seed it onto existing installs.
     */
    public function seedAssetAnalysisStep(): void
    {
        $this->clearModelFlags(AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS);

        AiOperation::updateOrCreate(
            ['operation_key' => AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS],
            [
                'label' => 'Storyboard · Reference Analysis',
                'default_model' => self::TEXT_MODEL,
                'fallback_model' => self::TEXT_FALLBACK,
                'image_quality' => null,
                'aspect_ratio' => null,
                'params' => ['temperature' => 0.2, 'top_p' => 0.9, 'max_tokens' => 1500],
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'subject_type' => ['type' => 'string', 'enum' => StoryboardAsset::TYPES],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['subject_type', 'description'],
                ],
                'retention_days' => null,
                'estimated_cost_micro_usd' => 5_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS, self::TEXT_MODEL, self::TEXT_MODEL_LABEL, isDefault: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_MODEL_HINT);
        $this->seedModel(AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS, self::TEXT_FALLBACK, self::TEXT_FALLBACK_LABEL, isFallback: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_FALLBACK_HINT);

        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS,
            'You are a film continuity supervisor analyzing ONE reference image. Report exactly what is VISIBLE — never invent, never embellish. Return: subject_type (character, location, product, logo, style, outfit or prop) and a description — one dense English paragraph an image model could use to repaint this subject IDENTICALLY with no access to the image. For a person: apparent age, gender presentation, build, skin tone, face shape, hair (color, length, style), eyes, eyebrows, facial hair, distinguishing marks, and the FULL outfit as worn (each garment with its color, fabric and fit, plus shoes and accessories). For a product or logo: exact shape, colors, materials, any visible text or lettering, and proportions. For a location: architecture or terrain, time of day, lighting, and notable set dressing. Return ONLY a JSON object matching the schema.',
            "Tag: @{{tag}} (declared type: {{declared_type}}).\nAnalyze the attached reference image.\nReturn strict JSON for the schema.",
        );
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
     *  the model id is volatile so verify it + set a BytePlus key before generating clips. Public
     *  so a data migration can re-seed JUST this step. */
    public function seedClipStep(): void
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
                // No fixed duration: each clip runs ITS frame's locked shot length, clamped
                // into these admin-editable bounds (StoryboardClipGenerator::clipSeconds).
                'params' => ['resolution' => '720p', 'ratio' => 'adaptive', 'min_clip_seconds' => 3, 'max_clip_seconds' => 12],
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
        // NON-default fal.ai video options (ids verified in the live fal catalog).
        $this->seedModel(AiOperation::KEY_STORYBOARD_CLIP, self::CLIP_FAL_KLING, 'Kling 2.5 Turbo Pro (fal.ai)', unit: AiModel::UNIT_PER_IMAGE, costHint: 200_000, provider: AiModel::PROVIDER_FAL);
        $this->seedModel(AiOperation::KEY_STORYBOARD_CLIP, self::CLIP_FAL_VEO, 'Veo 3.1 Fast (fal.ai)', unit: AiModel::UNIT_PER_IMAGE, costHint: 200_000, provider: AiModel::PROVIDER_FAL);

        // Video providers take ONE prompt string (no system message), so everything the clip needs
        // lives in the user template. {{motion}} is the frame's motion_prompt (the locked camera
        // move + subject motion); {{camera}} is the shot's camera work (angle — composition);
        // {{dialogue}} is the frame's spoken line (pre-formatted, empty when silent).
        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_CLIP,
            null,
            "{{image_prompt}}\n\nAnimate this exact frame into a short cinematic clip. Camera and subject motion: {{motion}}. Camera work: {{camera}}. Keep the characters, wardrobe, lighting, composition and art style identical to the source image; motion must be smooth, subtle and physically plausible — no morphing, no flicker, no new elements or on-screen text.\n{{dialogue}}",
        );
    }

    /** Seed one text step: the operation, its allow-listed models, and its global prompt. */
    private function seedTextStep(string $key, string $label, string $system, string $user, array $schema, ?array $params = null, ?int $estimate = null): void
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
                'params' => $params ?? self::TEXT_PARAMS,
                'input_schema' => $schema,
                'retention_days' => null,
                'estimated_cost_micro_usd' => $estimate ?? self::TEXT_STEP_EST,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel($key, self::TEXT_MODEL, self::TEXT_MODEL_LABEL, isDefault: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_MODEL_HINT);
        $this->seedModel($key, self::TEXT_FALLBACK, self::TEXT_FALLBACK_LABEL, isFallback: true, unit: AiModel::UNIT_PER_1K_TOKENS, costHint: self::TEXT_FALLBACK_HINT);

        $this->seedPrompt($key, $system, $user);
    }

    /** Seed the frame-image generation step (an image operation, like banner/try-on). Public so a
     *  data migration can re-seed JUST this step without resetting the text steps' admin edits. */
    public function seedFrameImageStep(): void
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
                // first_frame_model: the anchor-less LOOK-SETTING generation (usually frame 1)
                // upgrades to this premium model; every chained frame runs the edit-capable
                // default. Low temperature — continuity wants low sampler variance.
                'params' => ['temperature' => 0.3, 'top_p' => 0.9, 'first_frame_model' => self::IMAGE_FIRST_MODEL],
                'input_schema' => null,
                'retention_days' => null,
                'estimated_cost_micro_usd' => 45_000,
                'credit_multiplier' => null,
            ],
        );

        $this->seedModel(AiOperation::KEY_STORYBOARD_FRAME_IMAGE, self::IMAGE_MODEL, self::IMAGE_MODEL_LABEL, isDefault: true, unit: AiModel::UNIT_PER_IMAGE, costHint: self::IMAGE_MODEL_HINT, provider: self::IMAGE_PROVIDER);
        // The first-frame premium model + the cheap/legacy options stay catalogued (the admin
        // switches in settings; first_frame_model resolves through this catalog).
        $this->seedModel(AiOperation::KEY_STORYBOARD_FRAME_IMAGE, self::IMAGE_FIRST_MODEL, self::IMAGE_FIRST_LABEL, unit: AiModel::UNIT_PER_IMAGE, costHint: self::IMAGE_ALT_HINT);
        $this->seedModel(AiOperation::KEY_STORYBOARD_FRAME_IMAGE, self::IMAGE_MODEL_GEMINI, 'Gemini 2.5 Flash Image', unit: AiModel::UNIT_PER_IMAGE, costHint: 40_000);
        $this->seedModel(AiOperation::KEY_STORYBOARD_FRAME_IMAGE, self::IMAGE_MODEL_KREA, self::IMAGE_MODEL_KREA_LABEL, unit: AiModel::UNIT_PER_IMAGE, costHint: self::IMAGE_MODEL_KREA_HINT, provider: self::IMAGE_PROVIDER);

        // This system prompt IS applied at generation time (prepended to the frame's own
        // image_prompt) — it must stay placeholder-free: it is substituted with no vars, so any
        // {{token}} would reach the image model literally.
        $this->seedPrompt(
            AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
            'Create ONE cinematic still frame of a film. Follow the prompt EXACTLY: the characters\' faces, hair, wardrobe and colors, the location, the camera, lens, lighting, composition and the stated art style. When the previous shot of the film is attached, it is the CONTINUITY ANCHOR: keep the same people, wardrobe, palette, lighting and art style — only the staging changes. Attached reference images are ground truth for IDENTITY only — match each person\'s face, age, hair and body from them, but dress them EXACTLY as the prompt says and NEVER copy the reference\'s background, pose, lighting or camera angle. When an existing version of this frame is attached, treat it as the shot to EDIT: preserve its composition, characters and style and change only what the prompt asks. Deliver a clean full-bleed frame: no borders, watermarks, signatures or UI, and no text unless the prompt explicitly specifies on-screen text.',
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

    /** The ONE Story Director output: every planning section, locked in a single call. */
    private function storyDirectorSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'story' => $this->storySchema(),
                'genre_profile' => $this->genreSchema(),
                'characters' => $this->charactersSchema(),
                'visual_bible' => $this->visualBibleSchema(),
                'shot_timing' => $this->shotTimingSchema(),
            ],
            'required' => ['story', 'genre_profile', 'characters', 'visual_bible', 'shot_timing'],
        ];
    }

    private function storySchema(): array
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
                'content_type' => ['type' => 'string', 'enum' => [StoryboardProject::CONTENT_COMPLETE, StoryboardProject::CONTENT_TRAILER]],
            ],
            'required' => ['clean_story_summary', 'main_intent', 'creative_direction', 'content_type'],
        ];
    }

    /**
     * The LOCKED cut list: the DIRECTOR decides the shot count (within the project's bounds).
     * One shot = one continuous camera setup/movement = one frame; camera_movement is the
     * concrete executable move that drives both the still's staging and the clip's animation.
     */
    private function shotTimingSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'shot_number' => ['type' => 'integer'],
                    'duration_seconds' => ['type' => 'integer'],
                    'camera_movement' => ['type' => 'string'],
                    'shot_purpose' => ['type' => ['string', 'null']],
                ],
                'required' => ['shot_number', 'duration_seconds', 'camera_movement'],
            ],
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
                    // ONLY analysis-observed identity features for a @tag-bound character —
                    // never invented ones (the reference image itself is the identity).
                    'identity_lock' => ['type' => ['string', 'null']],
                    'outfit' => ['type' => ['string', 'null']],
                    'story_wardrobe' => ['type' => ['string', 'null']],
                    'scale_reference' => ['type' => ['string', 'null']],
                    'signature_prop' => ['type' => ['string', 'null']],
                    'default_expression' => ['type' => ['string', 'null']],
                    'movement_style' => ['type' => ['string', 'null']],
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
        // No start/end here on purpose — timing is LOCKED by the Story Director's plan and
        // stamped in code; scene_prompt is the per-beat scene only (the composer appends the
        // locked character + style blocks deterministically).
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
                            'description' => ['type' => 'string'],
                            'camera_angle' => ['type' => ['string', 'null']],
                            'composition' => ['type' => ['string', 'null']],
                            'action' => ['type' => ['string', 'null']],
                            'characters' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'reference_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'text_overlay' => ['type' => ['string', 'null']],
                            'motion' => ['type' => ['string', 'null']],
                            'scene_prompt' => ['type' => 'string'],
                            'negative_prompt' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['frame_number', 'description', 'scene_prompt'],
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
