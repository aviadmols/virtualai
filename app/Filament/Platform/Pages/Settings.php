<?php

namespace App\Filament\Platform\Pages;

use App\Domain\Ai\BytePlusImageClient;
use App\Domain\Ai\OpenRouterClient;
use App\Domain\Platform\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Platform Settings — the Super-Admin control plane for platform-wide secrets the
 * admin manages from the UI without a redeploy: the OpenRouter API key (used by every
 * scan + try-on call) and the PayPlus payment credentials.
 *
 * Secrets are WRITE-ONLY: the stored value is NEVER loaded into the browser (the rule
 * "the OpenRouter key never reaches the browser"). Each field starts blank with a
 * "configured / not set" hint; a blank field on save keeps the current value, a filled
 * one overwrites it (stored encrypted via PlatformSettings → PlatformSetting). Reads
 * fall back to the env var, so this is additive — env-only deploys keep working.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'platform.nav.controls';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.platform.pages.settings';

    private const NAV_LABEL = 'platform.settings.nav';
    private const TITLE = 'platform.settings.title';
    private const SAVED = 'platform.settings.saved';

    // form field → setting key. Every field here is a write-only secret.
    private const FIELD_SETTINGS = [
        'openrouter_api_key' => PlatformSettings::OPENROUTER_API_KEY,
        'byteplus_api_key' => PlatformSettings::BYTEPLUS_API_KEY,
        'payplus_api_key' => PlatformSettings::PAYPLUS_API_KEY,
        'payplus_secret_key' => PlatformSettings::PAYPLUS_SECRET_KEY,
        'payplus_page_uid' => PlatformSettings::PAYPLUS_PAGE_UID,
        'payplus_webhook_secret' => PlatformSettings::PAYPLUS_WEBHOOK_SECRET,
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
        // Write-only: never hydrate the stored secret into the form/browser.
        $this->form->fill();
    }

    /**
     * Header actions: a no-spend "Test connection" per AI provider — proves the configured
     * (or just-typed) key works so the admin never runs a real try-on to find it's wrong.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->testAction('testOpenRouter', 'openrouter_api_key', 'platform.settings.openrouter', fn (?string $k) => app(OpenRouterClient::class)->checkConnection($k)),
            $this->testAction('testByteplus', 'byteplus_api_key', 'platform.settings.byteplus', fn (?string $k) => app(BytePlusImageClient::class)->checkConnection($k)),
        ];
    }

    /** A provider "Test connection" header action (tests the typed value, else the saved key). */
    private function testAction(string $name, string $field, string $labelPrefix, callable $probe): Action
    {
        return Action::make($name)
            ->label(__($labelPrefix.'.test'))
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(function () use ($field, $labelPrefix, $probe): void {
                $typed = $this->data[$field] ?? null;
                $result = $probe(is_string($typed) ? $typed : null);

                $bodyKey = [
                    'not_configured' => $labelPrefix.'.test_not_configured',
                    'invalid_key' => $labelPrefix.'.test_invalid',
                    'timeout' => $labelPrefix.'.test_timeout',
                ][$result['reason']] ?? null;

                $body = $bodyKey !== null ? __($bodyKey) : ($result['detail'] ?? $result['message']);

                $notification = Notification::make()->body($body);

                $result['ok']
                    ? $notification->success()->title(__($labelPrefix.'.test_ok'))
                    : $notification->danger()->title(__($labelPrefix.'.test_fail'));

                $notification->send();
            });
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('platform.settings.openrouter.title'))
                    ->description(__('platform.settings.openrouter.sub'))
                    ->schema([
                        $this->secretField('openrouter_api_key', 'platform.settings.openrouter.api_key', PlatformSettings::OPENROUTER_API_KEY),
                    ]),
                Section::make(__('platform.settings.byteplus.title'))
                    ->description(__('platform.settings.byteplus.sub'))
                    ->schema([
                        $this->secretField('byteplus_api_key', 'platform.settings.byteplus.api_key', PlatformSettings::BYTEPLUS_API_KEY),
                    ]),
                Section::make(__('platform.settings.payplus.title'))
                    ->description(__('platform.settings.payplus.sub'))
                    ->columns(2)
                    ->schema([
                        $this->secretField('payplus_api_key', 'platform.settings.payplus.api_key', PlatformSettings::PAYPLUS_API_KEY),
                        $this->secretField('payplus_secret_key', 'platform.settings.payplus.secret_key', PlatformSettings::PAYPLUS_SECRET_KEY),
                        $this->secretField('payplus_page_uid', 'platform.settings.payplus.page_uid', PlatformSettings::PAYPLUS_PAGE_UID),
                        $this->secretField('payplus_webhook_secret', 'platform.settings.payplus.webhook_secret', PlatformSettings::PAYPLUS_WEBHOOK_SECRET),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(PlatformSettings::class);

        foreach (self::FIELD_SETTINGS as $field => $key) {
            $value = $data[$field] ?? null;

            // Blank keeps the current value (write-only); a filled field overwrites it.
            if (filled($value)) {
                $settings->put($key, (string) $value);
            }
        }

        // Never retain the entered secrets in the Livewire component state.
        $this->form->fill();

        Notification::make()
            ->success()
            ->title(__(self::SAVED))
            ->send();
    }

    /** A masked, write-only secret input that hints whether the value is configured. */
    private function secretField(string $field, string $labelKey, string $settingKey): TextInput
    {
        $configured = app(PlatformSettings::class)->isConfigured($settingKey);

        return TextInput::make($field)
            ->label(__($labelKey))
            ->password()
            ->revealable()
            ->autocomplete(false)
            ->placeholder(__($configured ? 'platform.settings.status.configured' : 'platform.settings.status.unset'))
            ->helperText(__('platform.settings.secret_help'));
    }
}
