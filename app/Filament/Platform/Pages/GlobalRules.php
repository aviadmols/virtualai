<?php

namespace App\Filament\Platform\Pages;

use App\Models\PlatformDirective;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Global Rules — the Super-Admin control plane for platform-wide "fixed rules" (art-direction /
 * constraints) that apply across ALL sites: one set for Image Studio (packshot + on-model), one
 * for Try-On. When active, a surface's rules are appended to the SYSTEM prompt of every generation
 * of that surface (AiOperationResolver), so a single directive shapes every merchant's output.
 *
 * A meaningful edit bumps the directive's `version`, which folds into the generation idempotency
 * keys — so a rule change re-generates instead of colliding as a duplicate (money-safe). Empty /
 * inactive rules are a no-op. Platform-only (super-admin panel); a merchant can never see or set it.
 */
class GlobalRules extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'platform.nav.ai';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.platform.pages.global-rules';

    private const NAV_LABEL = 'platform.rules.nav';

    private const TITLE = 'platform.rules.title';

    private const SAVED = 'platform.rules.saved';

    // surface → the form fields that edit it.
    private const SURFACE_FIELDS = [
        PlatformDirective::SURFACE_IMAGE_STUDIO => ['rules' => 'image_studio_rules', 'active' => 'image_studio_active'],
        PlatformDirective::SURFACE_TRY_ON => ['rules' => 'try_on_rules', 'active' => 'try_on_active'],
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
        $hydrated = [];

        foreach (self::SURFACE_FIELDS as $surface => $fields) {
            $row = PlatformDirective::query()->where('surface', $surface)->first();
            $hydrated[$fields['rules']] = (string) ($row?->rules ?? '');
            $hydrated[$fields['active']] = (bool) ($row?->is_active ?? false);
        }

        $this->form->fill($hydrated);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('platform.rules.image_studio.title'))
                    ->description(__('platform.rules.image_studio.sub'))
                    ->schema($this->surfaceFields('image_studio_rules', 'image_studio_active')),
                Section::make(__('platform.rules.try_on.title'))
                    ->description(__('platform.rules.try_on.sub'))
                    ->schema($this->surfaceFields('try_on_rules', 'try_on_active')),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (self::SURFACE_FIELDS as $surface => $fields) {
            $rules = trim((string) ($data[$fields['rules']] ?? ''));
            $active = (bool) ($data[$fields['active']] ?? false);

            $row = PlatformDirective::query()->where('surface', $surface)->first();

            // Bump the version ONLY on a meaningful change, so an idle save never churns the
            // generation idempotency keys (a version bump makes existing images re-generate).
            $changed = $row === null
                || (string) ($row->rules ?? '') !== $rules
                || (bool) $row->is_active !== $active;

            PlatformDirective::query()->updateOrCreate(
                ['surface' => $surface],
                [
                    'rules' => $rules === '' ? null : $rules,
                    'is_active' => $active,
                    'version' => $changed ? (($row?->version ?? 0) + 1) : ($row?->version ?? 1),
                ],
            );
        }

        $this->mount();

        Notification::make()->success()->title(__(self::SAVED))->send();
    }

    /** The two fields (rules textarea + active toggle) that edit one surface. */
    private function surfaceFields(string $rulesField, string $activeField): array
    {
        return [
            Textarea::make($rulesField)
                ->label(__('platform.rules.field.rules'))
                ->helperText(__('platform.rules.field.rules_help'))
                ->rows(5)
                ->maxLength(2000),
            Toggle::make($activeField)
                ->label(__('platform.rules.field.active'))
                ->helperText(__('platform.rules.field.active_help')),
        ];
    }
}
