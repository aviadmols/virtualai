<?php

namespace App\Filament\Platform\Pages;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Credits\CreditMath;
use App\Domain\Media\MediaStorage;
use App\Jobs\RunPlaygroundJob;
use App\Models\AiModel;
use App\Models\PlaygroundRun;
use Filament\Forms\Components\FileUpload;
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

/**
 * Model Playground — a standalone Super-Admin tool to TEST any image/video model directly.
 *
 * The admin writes a prompt, optionally attaches input images, picks a provider + model, and runs
 * it; the result, its render time, and its cost land in a live history gallery. It is deliberately
 * separate from the merchant/shopper flows: NO tenant, NO credit ledger, NO charge — a run only
 * calls the provider and records what came back. Images run across every provider; video runs on
 * BytePlus (Seedance) via the async task poller. The model id is free text so a brand-new model
 * (e.g. Seedance 2.0) can be tested without cataloguing it first.
 */
class ModelPlayground extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.platform.pages.model-playground';

    private const NAV_LABEL = 'platform.playground.nav';
    private const TITLE = 'platform.playground.title';
    private const RUN_STARTED = 'platform.playground.started';

    // How many recent runs the history gallery shows.
    private const RUN_LIMIT = 30;

    // Below this many milliseconds a duration reads "N ms"; at/above it reads "N.N s".
    private const MS_SECOND_THRESHOLD = 1000;

    // Form defaults + limits (consts, not scattered literals). The video defaults mirror
    // BytePlusVideoClient's DEFAULT_RESOLUTION / DEFAULT_DURATION so the page + client can't drift.
    private const DEFAULT_RESOLUTION = '720p';
    private const DEFAULT_DURATION = 5;
    private const MAX_INPUT_FILES = 4;
    private const MAX_INPUT_KB = 5120;
    private const PROMPT_PREVIEW_CHARS = 140;

    // Provider id → display label (image: all; video: byteplus + atlascloud).
    private const PROVIDER_LABELS = [
        ImageGenerationProvider::PROVIDER_OPENROUTER => 'OpenRouter',
        ImageGenerationProvider::PROVIDER_BYTEPLUS => 'BytePlus',
        ImageGenerationProvider::PROVIDER_XAI => 'xAI (Grok)',
        ImageGenerationProvider::PROVIDER_ATLASCLOUD => 'AtlasCloud',
    ];

    // The async VIDEO-capable providers offered for a video run.
    private const VIDEO_PROVIDER_LABELS = [
        ImageGenerationProvider::PROVIDER_BYTEPLUS => 'BytePlus',
        ImageGenerationProvider::PROVIDER_ATLASCLOUD => 'AtlasCloud',
    ];

    // Video resolution + ratio choices (only these knobs are sent to the video provider).
    private const RESOLUTIONS = ['480p', '720p', '1080p'];
    private const RATIOS = ['16:9', '9:16', '1:1', '4:3', '3:4', 'adaptive'];

    // Known video model ids offered as datalist suggestions per video provider (free text — verify
    // per account: BytePlus/Seedance ids vs AtlasCloud path-style ids).
    private const VIDEO_MODEL_SUGGESTIONS = [
        ImageGenerationProvider::PROVIDER_BYTEPLUS => [
            'dreamina-seedance-2-0-260128',
            'dreamina-seedance-2-0-fast-260128',
            'seedance-1-0-pro-250528',
            'seedance-1-0-lite-i2v-250428',
        ],
        ImageGenerationProvider::PROVIDER_ATLASCLOUD => [
            'bytedance/seedance-2.0/reference-to-video',
            'bytedance/seedance-2.0/image-to-video',
        ],
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
        $this->form->fill([
            'kind' => PlaygroundRun::KIND_IMAGE,
            'provider' => PlaygroundRun::PROVIDER_BYTEPLUS,
            'resolution' => self::DEFAULT_RESOLUTION,
            'duration' => self::DEFAULT_DURATION,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('platform.playground.form.title'))
                    ->description(__('platform.playground.form.sub'))
                    ->columns(2)
                    ->schema([
                        Select::make('kind')
                            ->label(__('platform.playground.field.kind'))
                            ->options([
                                PlaygroundRun::KIND_IMAGE => __('platform.playground.kind.image'),
                                PlaygroundRun::KIND_VIDEO => __('platform.playground.kind.video'),
                            ])
                            ->default(PlaygroundRun::KIND_IMAGE)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required(),
                        Select::make('provider')
                            ->label(__('platform.playground.field.provider'))
                            ->options(fn (Get $get): array => self::providerOptions((string) $get('kind')))
                            ->default(PlaygroundRun::PROVIDER_BYTEPLUS)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required()
                            ->helperText(__('platform.playground.field.provider_help')),
                        TextInput::make('model_id')
                            ->label(__('platform.playground.field.model_id'))
                            ->placeholder(__('platform.playground.field.model_id_placeholder'))
                            ->helperText(__('platform.playground.field.model_id_help'))
                            ->datalist(fn (Get $get): array => self::modelSuggestions((string) $get('kind'), (string) $get('provider')))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('prompt')
                            ->label(__('platform.playground.field.prompt'))
                            ->rows(4)
                            ->required()
                            ->columnSpanFull(),
                        FileUpload::make('inputs')
                            ->label(__('platform.playground.field.inputs'))
                            ->helperText(__('platform.playground.field.inputs_help'))
                            ->image()
                            ->multiple()
                            ->maxFiles(self::MAX_INPUT_FILES)
                            ->maxSize(self::MAX_INPUT_KB)
                            ->disk(self::mediaDisk())
                            ->directory('playground/inputs')
                            ->visibility('private')
                            ->columnSpanFull(),
                        TextInput::make('price')
                            ->label(__('platform.playground.field.price'))
                            ->helperText(__('platform.playground.field.price_help'))
                            ->numeric()
                            ->prefix('$')
                            ->step('0.000001')
                            ->minValue(0),
                    ]),
                Section::make(__('platform.playground.video.title'))
                    ->description(__('platform.playground.video.sub'))
                    ->columns(3)
                    ->visible(fn (Get $get): bool => $get('kind') === PlaygroundRun::KIND_VIDEO)
                    ->schema([
                        Select::make('resolution')
                            ->label(__('platform.playground.field.resolution'))
                            ->options(array_combine(self::RESOLUTIONS, self::RESOLUTIONS))
                            ->default(self::DEFAULT_RESOLUTION),
                        TextInput::make('duration')
                            ->label(__('platform.playground.field.duration'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(15)
                            ->default(self::DEFAULT_DURATION),
                        Select::make('ratio')
                            ->label(__('platform.playground.field.ratio'))
                            ->options(array_combine(self::RATIOS, self::RATIOS))
                            ->placeholder(__('platform.playground.field.ratio_auto')),
                    ]),
            ])
            ->statePath('data');
    }

    /** Create the run row + dispatch the job. Never charges — this is a pure model test. */
    public function run(): void
    {
        $data = $this->form->getState();

        $kind = (string) $data['kind'];
        $provider = (string) $data['provider'];
        // A video run must use a video-capable provider; fall back to BytePlus so a stale image
        // provider selection can't mis-route.
        if ($kind === PlaygroundRun::KIND_VIDEO && ! in_array($provider, PlaygroundRun::VIDEO_PROVIDERS, true)) {
            $provider = PlaygroundRun::PROVIDER_BYTEPLUS;
        }

        $priceMicro = filled($data['price'] ?? null) ? CreditMath::usdToMicro((float) $data['price']) : null;

        $run = PlaygroundRun::create([
            'created_by' => auth()->id(),
            'kind' => $kind,
            'provider' => $provider,
            'model_id' => (string) $data['model_id'],
            'prompt' => (string) $data['prompt'],
            'input_paths' => array_values($data['inputs'] ?? []),
            'price_hint_micro_usd' => $priceMicro,
            'status' => PlaygroundRun::STATUS_QUEUED,
            'meta' => $this->buildMeta($kind, $provider, (string) $data['model_id'], $data),
        ]);

        RunPlaygroundJob::dispatch($run->id);

        Notification::make()->success()->title(__(self::RUN_STARTED))->send();
    }

    /**
     * The run's meta: the video knobs (resolution/duration/ratio) + the resolved BytePlus region
     * host for the model (from the catalog, if catalogued) so the job hits the right region.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null
     */
    private function buildMeta(string $kind, string $provider, string $modelId, array $data): ?array
    {
        $meta = [];

        if ($kind === PlaygroundRun::KIND_VIDEO) {
            $meta[PlaygroundRun::META_RESOLUTION] = (string) ($data['resolution'] ?? self::DEFAULT_RESOLUTION);
            $meta[PlaygroundRun::META_DURATION] = (int) ($data['duration'] ?? self::DEFAULT_DURATION);
            if (filled($data['ratio'] ?? null)) {
                $meta[PlaygroundRun::META_RATIO] = (string) $data['ratio'];
            }
        }

        if ($provider === PlaygroundRun::PROVIDER_BYTEPLUS) {
            $baseUrl = AiModel::query()
                ->where('provider', PlaygroundRun::PROVIDER_BYTEPLUS)
                ->where('model_id', $modelId)
                ->value('base_url');

            if (filled($baseUrl)) {
                $meta[PlaygroundRun::META_BASE_URL] = $baseUrl;
            }
        }

        return $meta !== [] ? $meta : null;
    }

    /**
     * Recent runs formatted for the history gallery (signed result urls, human time + cost).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRuns(): array
    {
        $media = app(MediaStorage::class);

        return PlaygroundRun::query()
            ->latest('id')
            ->limit(self::RUN_LIMIT)
            ->get()
            ->map(fn (PlaygroundRun $r): array => [
                'id' => $r->id,
                'isVideo' => $r->isVideo(),
                'model' => $r->model_id,
                'providerLabel' => self::PROVIDER_LABELS[$r->provider] ?? $r->provider,
                'prompt' => Str::limit((string) $r->prompt, self::PROMPT_PREVIEW_CHARS),
                'status' => $r->status,
                'statusLabel' => __('platform.playground.status.'.$r->status),
                'running' => ! $r->isTerminal(),
                'failed' => $r->status === PlaygroundRun::STATUS_FAILED,
                'error' => $r->error,
                'time' => $r->duration_ms !== null ? $this->formatDuration($r->duration_ms) : null,
                'cost' => $this->formatCost($r),
                'tokens' => $r->tokens_used,
                'resultUrl' => $r->result_path !== null ? $media->signedUrl($r->result_path) : null,
            ])
            ->all();
    }

    /** Providers offered for a kind — video is the async video-capable set. @return array<string,string> */
    private static function providerOptions(string $kind): array
    {
        if ($kind === PlaygroundRun::KIND_VIDEO) {
            return self::VIDEO_PROVIDER_LABELS;
        }

        return self::PROVIDER_LABELS;
    }

    /** Datalist model-id suggestions: catalogued models for images, known video ids per provider. */
    private static function modelSuggestions(string $kind, string $provider): array
    {
        if ($kind === PlaygroundRun::KIND_VIDEO) {
            return self::VIDEO_MODEL_SUGGESTIONS[$provider] ?? self::VIDEO_MODEL_SUGGESTIONS[PlaygroundRun::PROVIDER_BYTEPLUS];
        }

        return AiModel::query()
            ->where('provider', $provider !== '' ? $provider : PlaygroundRun::PROVIDER_OPENROUTER)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->unique()
            ->values()
            ->all();
    }

    private function formatDuration(int $ms): string
    {
        return $ms >= self::MS_SECOND_THRESHOLD
            ? number_format($ms / self::MS_SECOND_THRESHOLD, 1).' '.__('platform.playground.unit.seconds')
            : $ms.' '.__('platform.playground.unit.ms');
    }

    private function formatCost(PlaygroundRun $run): string
    {
        if ($run->cost_micro_usd === null) {
            return '—';
        }

        return '$'.number_format(CreditMath::microToUsd($run->cost_micro_usd), 4);
    }

    private static function mediaDisk(): string
    {
        return (string) config('trayon.media.disk');
    }
}
