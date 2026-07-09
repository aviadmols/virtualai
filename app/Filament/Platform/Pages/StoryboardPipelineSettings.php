<?php

namespace App\Filament\Platform\Pages;

use App\Domain\Ai\FalModelCatalog;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\StoryboardTextCaller;
use App\Domain\Playground\PlaygroundImageRunner;
use App\Domain\Storyboard\StoryboardStep;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Prompt;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Throwable;

/**
 * Storyboard Pipeline Settings — one place to control EVERY step of the storyboard engine.
 *
 * Each step (read idea, genre, characters, visual bible, scene breakdown, frame image, video clip)
 * is a DB-managed AiOperation + prompt + model; this page edits them all together: the provider +
 * model (the "engine"), the fallback, the system + user prompt, and the params — with no redeploy.
 * It writes through to ai_operations / prompts / ai_models (the same records the generic AI screens
 * use), so the pipeline picks up changes on the next run.
 */
class StoryboardPipelineSettings extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.platform.pages.storyboard-pipeline-settings';

    private const NAV_LABEL = 'platform.storyboard.pipeline_nav';
    private const TITLE = 'platform.storyboard.pipeline_title';
    private const SAVED = 'platform.storyboard.pipeline_saved';

    // A tiny neutral prompt for the image-step Test (a real generation).
    private const SAMPLE_IMAGE_PROMPT = 'a single red apple on a wooden table, soft cinematic lighting';

    // The steps this page controls (in pipeline order).
    private const STEPS = [
        AiOperation::KEY_STORYBOARD_READ_IDEA,
        AiOperation::KEY_STORYBOARD_GENRE,
        AiOperation::KEY_STORYBOARD_CHARACTERS,
        AiOperation::KEY_STORYBOARD_VISUAL_BIBLE,
        AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN,
        AiOperation::KEY_STORYBOARD_FRAME_IMAGE,
        AiOperation::KEY_STORYBOARD_CLIP,
    ];

    private const PROVIDERS = [
        AiModel::PROVIDER_OPENROUTER => 'OpenRouter',
        AiModel::PROVIDER_BYTEPLUS => 'BytePlus',
        AiModel::PROVIDER_XAI => 'xAI (Grok)',
        AiModel::PROVIDER_ATLASCLOUD => 'AtlasCloud',
        AiModel::PROVIDER_FAL => 'fal.ai',
    ];

    // Known model ids per provider — offered in the Model dropdown alongside catalogued models so
    // the common choices appear even before a model is catalogued. For fal the FULL public model
    // catalog is merged in dynamically (FalModelCatalog); these are just the pinned favorites.
    private const MODEL_SUGGESTIONS = [
        AiModel::PROVIDER_OPENROUTER => ['google/gemini-3.1-pro-preview', 'google/gemini-3.5-flash', 'google/gemini-2.5-flash', 'google/gemini-2.5-flash-image', 'google/gemini-3-pro-image'],
        AiModel::PROVIDER_BYTEPLUS => ['seedream-4-0-250828', 'seedream-5-0-260128', 'seedream-4-5-251128', 'dreamina-seedance-2-0-260128'],
        AiModel::PROVIDER_XAI => ['grok-2-image', 'grok-imagine-image-quality'],
        AiModel::PROVIDER_ATLASCLOUD => ['bytedance/seedance-2.0/reference-to-video', 'bytedance/seedance-2.0/image-to-video'],
        AiModel::PROVIDER_FAL => ['fal-ai/krea-2/turbo', 'fal-ai/flux/dev', 'fal-ai/nano-banana/edit', 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video', 'fal-ai/veo3.1/fast/image-to-video'],
    ];

    /** @var array<string,mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public function getTitle(): string
    {
        return __(self::TITLE);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public function mount(): void
    {
        $state = [];

        foreach (self::STEPS as $key) {
            $op = AiOperation::query()->where('operation_key', $key)->first();
            $prompt = $this->prompt($key);
            $provider = AiModel::query()->where('operation_key', $key)->where('model_id', $op?->default_model)->value('provider')
                ?? AiModel::PROVIDER_OPENROUTER;

            $state[$key] = [
                'provider' => $provider,
                'model' => $op?->default_model,
                'fallback_model' => $op?->fallback_model,
                'system_prompt' => $prompt?->system_prompt,
                'user_prompt' => $prompt?->user_prompt,
                'params' => $op?->params ?? [],
            ];
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form->schema($this->stepSections())->statePath('data');
    }

    /** A collapsible section per step with its engine + prompt + params. */
    private function stepSections(): array
    {
        $sections = [];

        foreach (self::STEPS as $key) {
            $sections[] = Section::make(__('platform.storyboard.step.'.$key))
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->headerActions([
                    FormAction::make('test_'.$key)
                        ->label(__('platform.storyboard.pipe.test'))
                        ->icon('heroicon-o-beaker')
                        ->action(fn () => $this->testStep($key)),
                ])
                ->schema([
                    Select::make($key.'.provider')
                        ->label(__('platform.storyboard.pipe.provider'))
                        ->options(self::PROVIDERS)
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make($key.'.model')
                        ->label(__('platform.storyboard.pipe.model'))
                        ->helperText(__('platform.storyboard.pipe.model_help'))
                        ->options(fn (Get $get): array => $this->modelOptions((string) $get($key.'.provider'), (string) $get($key.'.model'), $key))
                        ->searchable()
                        ->native(false)
                        ->required(),
                    TextInput::make($key.'.fallback_model')
                        ->label(__('platform.storyboard.pipe.fallback')),
                    KeyValue::make($key.'.params')
                        ->label(__('platform.storyboard.pipe.params'))
                        ->keyLabel(__('platform.storyboard.pipe.param_key'))
                        ->valueLabel(__('platform.storyboard.pipe.param_value')),
                    Textarea::make($key.'.system_prompt')
                        ->label(__('platform.storyboard.pipe.system'))
                        ->rows(4)
                        ->columnSpanFull(),
                    Textarea::make($key.'.user_prompt')
                        ->label(__('platform.storyboard.pipe.user'))
                        ->rows(4)
                        ->columnSpanFull(),
                ]);
        }

        return $sections;
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (self::STEPS as $key) {
            $step = $data[$key] ?? null;
            if (! is_array($step)) {
                continue;
            }

            $op = AiOperation::query()->where('operation_key', $key)->first();
            if ($op === null) {
                continue;
            }

            $op->update([
                'default_model' => $step['model'],
                'fallback_model' => filled($step['fallback_model']) ? $step['fallback_model'] : null,
                'params' => $this->coerceParams(is_array($step['params']) ? $step['params'] : []),
            ]);

            Prompt::updateOrCreate(
                ['scope' => Prompt::SCOPE_GLOBAL, 'operation_key' => $key, 'product_type' => null, 'account_id' => null, 'site_id' => null],
                ['system_prompt' => $step['system_prompt'], 'user_prompt' => $step['user_prompt'], 'is_active' => true],
            );

            $this->upsertModel($key, (string) $step['model'], (string) $step['provider'], isDefault: true);
            if (filled($step['fallback_model'])) {
                $this->upsertModel($key, (string) $step['fallback_model'], (string) $step['provider'], isFallback: true);
            }
        }

        Notification::make()->success()->title(__(self::SAVED))->send();
    }

    /**
     * Test a single step with its CURRENT (possibly unsaved) prompt + model against sample inputs,
     * and report whether it returns valid JSON — the fast way to verify a prompt before running the
     * whole storyboard. Text steps run the real caller; image/video are tested in the Playground.
     */
    public function testStep(string $key): void
    {
        $step = data_get($this->data, $key);
        if (! is_array($step)) {
            return;
        }

        $provider = (string) ($step['provider'] ?? AiModel::PROVIDER_OPENROUTER);
        $model = (string) ($step['model'] ?? '');

        if (StoryboardStep::isTextStep($key)) {
            $this->testTextStep($key, $step, $provider, $model);

            return;
        }

        if ($key === AiOperation::KEY_STORYBOARD_FRAME_IMAGE) {
            $this->testImageStep($provider, $model);

            return;
        }

        // Video clip step — a real submit+poll is async/spendy; point to the Playground.
        Notification::make()->info()
            ->title(__('platform.storyboard.pipe.test'))
            ->body(__('platform.storyboard.pipe.test_media_hint'))
            ->send();
    }

    /** @param array<string,mixed> $step */
    private function testTextStep(string $key, array $step, string $provider, string $model): void
    {
        // Text steps return structured JSON — only OpenRouter serves them.
        if ($provider !== AiModel::PROVIDER_OPENROUTER) {
            Notification::make()->warning()
                ->title(__('platform.storyboard.pipe.test'))
                ->body(__('platform.storyboard.pipe.test_text_needs_openrouter'))
                ->send();

            return;
        }

        $op = AiOperation::query()->where('operation_key', $key)->first();

        $config = new OperationConfig(
            operationKey: $key,
            model: $model,
            fallbackModel: filled($step['fallback_model'] ?? null) ? $step['fallback_model'] : null,
            systemPrompt: $step['system_prompt'] ?? null,
            userPrompt: (string) ($step['user_prompt'] ?? ''),
            imageQuality: null,
            aspectRatio: null,
            params: is_array($step['params'] ?? null) ? $step['params'] : [],
            creditMultiplier: null,
            promptVersion: 1,
            estimatedCostMicroUsd: $op?->estimated_cost_micro_usd,
            inputSchema: $op?->input_schema,
            provider: $provider,
        );

        try {
            $result = app(StoryboardTextCaller::class)->extract($config, $this->sampleVars());
            Notification::make()->success()
                ->title(__('platform.storyboard.pipe.test_ok'))
                ->body(__('platform.storyboard.pipe.test_ok_body', ['keys' => implode(', ', array_keys($result->json))]))
                ->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->persistent()
                ->title(__('platform.storyboard.pipe.test_fail'))
                ->body(Str::limit($e->getMessage(), 500))
                ->send();
        }
    }

    /** A REAL image generation with the step's provider + model (works for OpenRouter/BytePlus/xAI). */
    private function testImageStep(string $provider, string $model): void
    {
        $op = AiOperation::query()->where('operation_key', AiOperation::KEY_STORYBOARD_FRAME_IMAGE)->first();

        try {
            $result = app(PlaygroundImageRunner::class)->run($provider, $model, self::SAMPLE_IMAGE_PROMPT, [], $op?->estimated_cost_micro_usd);
            Notification::make()->success()
                ->title(__('platform.storyboard.pipe.test_ok'))
                ->body(__('platform.storyboard.pipe.test_image_ok', ['kb' => (int) round(strlen($result->imageBytes) / 1024)]))
                ->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->persistent()
                ->title(__('platform.storyboard.pipe.test_fail'))
                ->body(Str::limit($e->getMessage(), 500))
                ->send();
        }
    }

    /** Canned placeholders so any single step can run standalone in a test. @return array<string,string> */
    private function sampleVars(): array
    {
        return [
            'story_idea' => 'A cinematic comedy trailer: a chaotic pool party with @hero at @location_pool.',
            'genre' => 'cinematic comedy trailer',
            'duration' => '15',
            'frame_interval' => '3',
            'frame_count' => '5',
            'aspect_ratio' => '16:9',
            'reference_tags' => '@hero, @location_pool',
            'clean_story' => '{"clean_story_summary":"A chaotic pool party trailer","main_intent":"entertain","creative_direction":"comedy trailer"}',
            'genre_profile' => '{"genre":"comedy trailer","emotional_tone":"fun"}',
            'characters' => '{"characters":[{"name":"Hero","description":"the party host"}]}',
            'visual_bible' => '{"global_style":"realistic cinematic","negative_prompt":"no cartoon"}',
        ];
    }

    // Numeric param keys → their type. The KeyValue editor yields strings; the APIs need numbers.
    private const NUMERIC_PARAMS = [
        'temperature' => 'float',
        'top_p' => 'float',
        'max_tokens' => 'int',
        'seed' => 'int',
        'duration_seconds' => 'int',
    ];

    /**
     * Coerce known numeric params (temperature, top_p, …) from strings to numbers so a saved value
     * doesn't become a string a provider rejects (a string temperature is a 400). @param array<string,mixed> $params @return array<string,mixed>
     */
    private function coerceParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (isset(self::NUMERIC_PARAMS[$key]) && is_numeric($value)) {
                $params[$key] = self::NUMERIC_PARAMS[$key] === 'int' ? (int) $value : (float) $value;
            }
        }

        return $params;
    }

    /**
     * The Model dropdown options for a provider: its catalogued models + the known suggestions +
     * the current value (so an existing custom id is never dropped). Reacts to the Provider select.
     * For fal the FULL public model catalog is merged in (image categories for the frame-image
     * step, video categories for the clip step) — the "browse every fal model" picker.
     *
     * @return array<string,string>
     */
    private function modelOptions(string $provider, string $current = '', ?string $stepKey = null): array
    {
        if ($provider === '') {
            $provider = AiModel::PROVIDER_OPENROUTER;
        }

        $options = [];

        foreach (AiModel::query()->where('provider', $provider)->orderBy('model_id')->pluck('label', 'model_id') as $id => $label) {
            $options[(string) $id] = filled($label) ? $id.' — '.$label : (string) $id;
        }

        foreach (self::MODEL_SUGGESTIONS[$provider] ?? [] as $id) {
            $options[$id] ??= $id;
        }

        if ($provider === AiModel::PROVIDER_FAL) {
            $categories = $stepKey === AiOperation::KEY_STORYBOARD_CLIP
                ? FalModelCatalog::VIDEO_CATEGORIES
                : FalModelCatalog::IMAGE_CATEGORIES;

            foreach (app(FalModelCatalog::class)->options($categories) as $id => $label) {
                $options[$id] ??= $label;
            }
        }

        if ($current !== '' && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    /** Upsert the ai_models row so the resolver knows the model's provider + that it's allowed. */
    private function upsertModel(string $key, string $modelId, string $provider, bool $isDefault = false, bool $isFallback = false): void
    {
        if ($modelId === '') {
            return;
        }

        if ($isDefault) {
            AiModel::query()->where('operation_key', $key)->update(['is_default' => false]);
        }

        AiModel::updateOrCreate(
            ['operation_key' => $key, 'model_id' => $modelId],
            array_filter([
                'provider' => $provider,
                'is_default' => $isDefault ?: null,
                'is_fallback' => $isFallback ?: null,
                'is_active' => true,
            ], static fn ($v): bool => $v !== null),
        );
    }

    private function prompt(string $key): ?Prompt
    {
        return Prompt::query()
            ->where('scope', Prompt::SCOPE_GLOBAL)
            ->where('operation_key', $key)
            ->whereNull('product_type')
            ->whereNull('account_id')
            ->whereNull('site_id')
            ->first();
    }
}
