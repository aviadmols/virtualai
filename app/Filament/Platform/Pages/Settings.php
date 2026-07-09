<?php

namespace App\Filament\Platform\Pages;

use App\Domain\Ai\BytePlusImageClient;
use App\Domain\Ai\FalImageClient;
use App\Domain\Ai\OpenRouterClient;
use App\Domain\Ai\XaiImageClient;
use App\Domain\Platform\PlatformMailConfig;
use App\Domain\Platform\PlatformSettings;
use App\Mail\PlatformTestMail;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

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

    // SMTP encryption choices (UI value → the smtp `scheme` PlatformMailConfig maps).
    private const SMTP_ENCRYPTION_OPTIONS = ['tls', 'ssl', 'none'];

    // form field → setting key. WRITE-ONLY secrets: blank on save KEEPS the stored value.
    private const FIELD_SETTINGS = [
        'openrouter_api_key' => PlatformSettings::OPENROUTER_API_KEY,
        'byteplus_api_key' => PlatformSettings::BYTEPLUS_API_KEY,
        'xai_api_key' => PlatformSettings::XAI_API_KEY,
        'fal_api_key' => PlatformSettings::FAL_API_KEY,
        'payplus_api_key' => PlatformSettings::PAYPLUS_API_KEY,
        'payplus_secret_key' => PlatformSettings::PAYPLUS_SECRET_KEY,
        'payplus_page_uid' => PlatformSettings::PAYPLUS_PAGE_UID,
        'payplus_webhook_secret' => PlatformSettings::PAYPLUS_WEBHOOK_SECRET,
        'smtp_password' => PlatformSettings::SMTP_PASSWORD,
    ];

    // form field → setting key. VISIBLE config values: hydrated in mount() so the admin
    // sees the current value; blank on save CLEARS the row (falls back to the env var).
    private const VISIBLE_SETTINGS = [
        'smtp_host' => PlatformSettings::SMTP_HOST,
        'smtp_port' => PlatformSettings::SMTP_PORT,
        'smtp_encryption' => PlatformSettings::SMTP_ENCRYPTION,
        'smtp_username' => PlatformSettings::SMTP_USERNAME,
        'mail_from_address' => PlatformSettings::MAIL_FROM_ADDRESS,
        'mail_from_name' => PlatformSettings::MAIL_FROM_NAME,
    ];

    /** @var array<string,mixed> */
    public ?array $data = [];

    /** The full text of the last failed connection test — rendered under the form ("Read all"). */
    public ?string $lastTestError = null;

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
        // Secrets (API keys + the SMTP password) stay BLANK — write-only, never hydrated
        // to the browser. Only the VISIBLE config values are pre-filled so the admin can
        // see and edit the current SMTP transport.
        $settings = app(PlatformSettings::class);
        $hydrated = [];

        foreach (self::VISIBLE_SETTINGS as $field => $key) {
            $hydrated[$field] = $settings->resolve($key);
        }

        $this->form->fill($hydrated);
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
            $this->testAction('testXai', 'xai_api_key', 'platform.settings.xai', fn (?string $k) => app(XaiImageClient::class)->checkConnection($k)),
            $this->testAction('testFal', 'fal_api_key', 'platform.settings.fal', fn (?string $k) => app(FalImageClient::class)->checkConnection($k)),
            $this->sendTestEmailAction(),
        ];
    }

    // How much of the raw transport error to show INLINE in the failure toast (the full text
    // stays in the "Read all" panel under the form).
    private const TEST_ERROR_PREVIEW_CHARS = 300;

    /**
     * "Send test email" — applies the stored SMTP config and sends a plain test message so the
     * admin can verify the transport without waiting for a real club signup. On failure the RAW
     * transport error is shown directly in the toast (truncated) AND kept in full behind "Read
     * all" — so the admin sees EXACTLY what SMTP rejected. The SMTP section points to this button.
     * Recipient defaults to the configured From address.
     */
    private function sendTestEmailAction(): Action
    {
        return Action::make('sendTestEmail')
            ->label(__('platform.settings.smtp.test'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->form([
                TextInput::make('recipient')
                    ->label(__('platform.settings.smtp.test_recipient'))
                    ->email()
                    ->required()
                    ->default(fn () => app(PlatformSettings::class)->resolve(PlatformSettings::MAIL_FROM_ADDRESS)),
            ])
            ->action(function (array $data): void {
                $recipient = (string) ($data['recipient'] ?? '');

                try {
                    // Bind the DB-stored SMTP transport, then send through it.
                    app(PlatformMailConfig::class)->apply();
                    Mail::to($recipient)->send(new PlatformTestMail);
                } catch (Throwable $e) {
                    // Surface the ACTUAL error: inline in the toast (truncated) + full in "Read all".
                    $error = trim($e->getMessage());
                    $this->lastTestError = $error;

                    Notification::make()
                        ->danger()
                        ->title(__('platform.settings.smtp.test_fail'))
                        ->body(Str::limit($error, self::TEST_ERROR_PREVIEW_CHARS))
                        ->persistent()
                        ->send();

                    return;
                }

                $this->lastTestError = null;

                Notification::make()
                    ->success()
                    ->title(__('platform.settings.smtp.test_ok'))
                    ->body(__('platform.settings.smtp.test_ok_body', ['email' => $recipient]))
                    ->send();
            });
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

                $body = $bodyKey !== null ? __($bodyKey) : ($result['message'] ?? '');

                // Keep the FULL error (message + provider detail) for the "Read all" panel.
                $this->lastTestError = $result['ok']
                    ? null
                    : trim(($result['message'] ?? '')."\n\n".($result['detail'] ?? ''));

                $notification = Notification::make()->body($body);

                if ($result['ok']) {
                    $notification->success()->title(__($labelPrefix.'.test_ok'));
                } else {
                    $notification->danger()->title(__($labelPrefix.'.test_fail'))->persistent();
                }

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
                Section::make(__('platform.settings.xai.title'))
                    ->description(__('platform.settings.xai.sub'))
                    ->schema([
                        $this->secretField('xai_api_key', 'platform.settings.xai.api_key', PlatformSettings::XAI_API_KEY),
                    ]),
                Section::make(__('platform.settings.fal.title'))
                    ->description(__('platform.settings.fal.sub'))
                    ->schema([
                        $this->secretField('fal_api_key', 'platform.settings.fal.api_key', PlatformSettings::FAL_API_KEY),
                    ]),
                Section::make(__('platform.settings.smtp.title'))
                    ->description(__('platform.settings.smtp.sub'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('smtp_host')
                            ->label(__('platform.settings.smtp.host'))
                            ->placeholder(__('platform.settings.smtp.host_placeholder'))
                            ->autocomplete(false),
                        TextInput::make('smtp_port')
                            ->label(__('platform.settings.smtp.port'))
                            ->numeric()
                            ->placeholder(__('platform.settings.smtp.port_placeholder')),
                        Select::make('smtp_encryption')
                            ->label(__('platform.settings.smtp.encryption'))
                            ->options($this->encryptionOptions())
                            ->native(false)
                            ->placeholder(__('platform.settings.smtp.encryption_placeholder')),
                        TextInput::make('smtp_username')
                            ->label(__('platform.settings.smtp.username'))
                            ->autocomplete(false),
                        $this->secretField('smtp_password', 'platform.settings.smtp.password', PlatformSettings::SMTP_PASSWORD),
                        TextInput::make('mail_from_address')
                            ->label(__('platform.settings.smtp.from_address'))
                            ->email()
                            ->placeholder(__('platform.settings.smtp.from_address_placeholder')),
                        TextInput::make('mail_from_name')
                            ->label(__('platform.settings.smtp.from_name'))
                            ->placeholder(__('platform.settings.smtp.from_name_placeholder')),
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

            // Blank keeps the current value (write-only secret); a filled field overwrites it.
            if (filled($value)) {
                $settings->put($key, (string) $value);
            }
        }

        foreach (self::VISIBLE_SETTINGS as $field => $key) {
            $value = $data[$field] ?? null;

            // Visible config: a blank field CLEARS the row (falls back to the env var);
            // a filled one overwrites it. Stored non-secret so it hydrates next mount().
            $settings->put($key, filled($value) ? (string) $value : null, secret: false);
        }

        // Never retain the entered secrets in the Livewire component state; re-hydrate
        // the visible values so the form reflects what was just saved.
        $this->mount();

        Notification::make()
            ->success()
            ->title(__(self::SAVED))
            ->send();
    }

    /** UI encryption choices (tls/ssl/none) keyed to their localized labels. */
    private function encryptionOptions(): array
    {
        $options = [];

        foreach (self::SMTP_ENCRYPTION_OPTIONS as $value) {
            $options[$value] = __('platform.settings.smtp.encryption_'.$value);
        }

        return $options;
    }

    /**
     * A masked, write-only secret input. When a value is already stored it shows a green
     * "Saved ✓" hint + a placeholder + helper so the admin can SEE it's set — the field is
     * intentionally blank (the secret never returns to the browser); leaving it blank keeps
     * the stored value, entering a new one replaces it.
     */
    private function secretField(string $field, string $labelKey, string $settingKey): TextInput
    {
        $configured = app(PlatformSettings::class)->isConfigured($settingKey);

        $input = TextInput::make($field)
            ->label(__($labelKey))
            ->password()
            ->revealable()
            ->autocomplete(false)
            ->placeholder(__($configured ? 'platform.settings.status.configured' : 'platform.settings.status.unset'))
            ->helperText(__($configured ? 'platform.settings.secret_saved' : 'platform.settings.secret_help'));

        if ($configured) {
            $input->hint(__('platform.settings.status.saved_hint'))
                ->hintColor('success')
                ->hintIcon('heroicon-m-check-circle');
        }

        return $input;
    }
}
