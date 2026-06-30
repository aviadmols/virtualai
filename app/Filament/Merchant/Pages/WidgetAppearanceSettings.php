<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Domain\Sites\WidgetAppearance;
use App\Models\Site;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-site widget appearance — where the Tray On button sits, its text + colours, and the
 * try-on popup's theme + accent. Binds 1:1 to SiteSettingsService::update() (the single
 * validated writer) which routes the appearance through WidgetAppearance::sanitize before
 * persisting the one whitelisted column; the storefront widget reads the resolved values
 * from the bootstrap API. Tenant-safe: the site resolves through the account-bound scope
 * (a foreign ?site= 404s); a ?site={id} deep-link selects another OWN site.
 */
class WidgetAppearanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.merchant.pages.widget-appearance-settings';

    private const NAV_LABEL = 'appearance.nav';
    private const TITLE = 'appearance.title';
    private const SAVED = 'appearance.saved';
    private const SAVE_FAILED = 'appearance.errors.save_failed';

    /** The bound site id (scalar — Livewire-safe; the model re-resolves on demand). */
    public ?int $siteId = null;

    public bool $hasSite = false;

    /** @var array<string,mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /** Resolve the site (deep-link or first) and hydrate the form from its appearance. */
    public function mount(): void
    {
        $site = request()->query('site');

        $resolved = $site !== null
            ? Site::query()->find($site)
            : Site::query()->orderBy('id')->first();

        if ($resolved === null) {
            $this->form->fill(WidgetAppearance::defaults());

            return;
        }

        $this->siteId = (int) $resolved->getKey();
        $this->hasSite = true;
        $this->form->fill(WidgetAppearance::resolve($resolved->widget_appearance));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('appearance.button.title'))
                    ->description(__('appearance.button.sub'))
                    ->columns(2)
                    ->schema([
                        Select::make(WidgetAppearance::KEY_PLACEMENT)
                            ->label(__('appearance.button.placement'))
                            ->options(self::placementOptions())
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make(WidgetAppearance::KEY_LABEL)
                            ->label(__('appearance.button.label'))
                            ->required()
                            ->maxLength(WidgetAppearance::LABEL_MAX),
                        ColorPicker::make(WidgetAppearance::KEY_BUTTON_BG)
                            ->label(__('appearance.button.bg'))
                            ->required(),
                        ColorPicker::make(WidgetAppearance::KEY_BUTTON_TEXT)
                            ->label(__('appearance.button.text'))
                            ->required(),
                    ]),
                Section::make(__('appearance.popup.title'))
                    ->description(__('appearance.popup.sub'))
                    ->columns(2)
                    ->schema([
                        Select::make(WidgetAppearance::KEY_POPUP_THEME)
                            ->label(__('appearance.popup.theme'))
                            ->options(self::themeOptions())
                            ->required(),
                        ColorPicker::make(WidgetAppearance::KEY_POPUP_ACCENT)
                            ->label(__('appearance.popup.accent'))
                            ->required(),
                        Toggle::make(WidgetAppearance::KEY_ASK_HEIGHT)
                            ->label(__('appearance.popup.ask_height'))
                            ->helperText(__('appearance.popup.ask_height_help'))
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Validate-then-persist via the service. A typed InvalidSiteSettingsException (bad
     * appearance value) or any other error surfaces a save-failed notice — never a 500,
     * never a partial save (the Filament form also validates before this runs).
     */
    public function save(): void
    {
        $site = $this->site();

        if ($site === null) {
            return;
        }

        try {
            app(SiteSettingsService::class)->update($site, [
                SiteSettingsService::KEY_WIDGET_APPEARANCE => $this->form->getState(),
            ]);

            Notification::make()->success()->title(__(self::SAVED))->send();
        } catch (InvalidSiteSettingsException | \Throwable) {
            Notification::make()->danger()->title(__(self::SAVE_FAILED))->send();
        }
    }

    /** The bound site (account-scoped), or null. */
    public function site(): ?Site
    {
        return $this->siteId !== null
            ? Site::query()->find($this->siteId)
            : null;
    }

    /** Placement value => localised label. */
    private static function placementOptions(): array
    {
        $options = [];

        foreach (WidgetAppearance::PLACEMENTS as $placement) {
            $options[$placement] = __('appearance.placement.'.$placement);
        }

        return $options;
    }

    /** Popup theme value => localised label. */
    private static function themeOptions(): array
    {
        $options = [];

        foreach (WidgetAppearance::THEMES as $theme) {
            $options[$theme] = __('appearance.theme.'.$theme);
        }

        return $options;
    }
}
