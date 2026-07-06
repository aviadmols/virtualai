<?php

namespace App\Domain\Platform;

/**
 * PlatformMailConfig — binds the Super-Admin-managed SMTP settings into the live
 * mail config right before a send, so Mail:: uses the DB-stored transport without
 * touching the Railway env.
 *
 * apply() is ON-DEMAND: it is called by the mail-sending path (e.g. the club OTP
 * send), NEVER on every request boot — so the widget hot path never reads these
 * settings. When an SMTP host is configured (DB value OR env fallback via
 * PlatformSettings::resolve), it points mail.default at the smtp mailer and fills
 * mail.mailers.smtp.* + mail.from.* from the resolved settings. When no host is
 * configured, it is a no-op — the app keeps whatever mailer the env chose (e.g.
 * MAIL_MAILER=log in dev), so nothing regresses.
 *
 * The SMTP password is a secret resolved the same way as the API keys; it is set
 * into runtime config only (never logged, never returned to a browser).
 */
final class PlatformMailConfig
{
    // === CONSTANTS ===
    // Runtime config keys this binder writes (Laravel-11 smtp mailer contract).
    private const CONFIG_DEFAULT = 'mail.default';

    private const CONFIG_HOST = 'mail.mailers.smtp.host';

    private const CONFIG_PORT = 'mail.mailers.smtp.port';

    private const CONFIG_SCHEME = 'mail.mailers.smtp.scheme';

    private const CONFIG_USERNAME = 'mail.mailers.smtp.username';

    private const CONFIG_PASSWORD = 'mail.mailers.smtp.password';

    private const CONFIG_FROM_ADDRESS = 'mail.from.address';

    private const CONFIG_FROM_NAME = 'mail.from.name';

    // The mailer to switch to once an SMTP host is present.
    private const MAILER_SMTP = 'smtp';

    // "none" in the UI means no TLS/SSL scheme — send it as null to the transport.
    private const ENCRYPTION_NONE = 'none';

    public function __construct(
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * Fold the stored SMTP settings into the live mail config. No-op when no host is
     * configured (DB or env), so env-only / log-mailer deploys are unaffected.
     */
    public function apply(): void
    {
        $host = $this->settings->resolve(PlatformSettings::SMTP_HOST);

        if (! filled($host)) {
            return;
        }

        config([
            self::CONFIG_DEFAULT => self::MAILER_SMTP,
            self::CONFIG_HOST => $host,
        ]);

        $this->applyIfResolved(PlatformSettings::SMTP_PORT, self::CONFIG_PORT, static fn (string $v): int => (int) $v);
        $this->applyEncryption();
        $this->applyIfResolved(PlatformSettings::SMTP_USERNAME, self::CONFIG_USERNAME);
        $this->applyIfResolved(PlatformSettings::SMTP_PASSWORD, self::CONFIG_PASSWORD);
        $this->applyIfResolved(PlatformSettings::MAIL_FROM_ADDRESS, self::CONFIG_FROM_ADDRESS);
        $this->applyIfResolved(PlatformSettings::MAIL_FROM_NAME, self::CONFIG_FROM_NAME);
    }

    /** Set a config key from a resolved setting when present; leave the env value otherwise. */
    private function applyIfResolved(string $settingKey, string $configKey, ?callable $transform = null): void
    {
        $value = $this->settings->resolve($settingKey);

        if (! filled($value)) {
            return;
        }

        config([$configKey => $transform !== null ? $transform($value) : $value]);
    }

    /** Map the UI encryption choice (tls/ssl/none) onto the smtp `scheme` (null = plain). */
    private function applyEncryption(): void
    {
        $encryption = $this->settings->resolve(PlatformSettings::SMTP_ENCRYPTION);

        if (! filled($encryption)) {
            return;
        }

        config([self::CONFIG_SCHEME => $encryption === self::ENCRYPTION_NONE ? null : $encryption]);
    }
}
