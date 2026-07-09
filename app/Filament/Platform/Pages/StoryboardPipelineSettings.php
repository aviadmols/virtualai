<?php

namespace App\Filament\Platform\Pages;

use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Prompt;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

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
                ->schema([
                    Select::make($key.'.provider')
                        ->label(__('platform.storyboard.pipe.provider'))
                        ->options(self::PROVIDERS)
                        ->selectablePlaceholder(false),
                    TextInput::make($key.'.model')
                        ->label(__('platform.storyboard.pipe.model'))
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
                'params' => is_array($step['params']) ? $step['params'] : [],
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
