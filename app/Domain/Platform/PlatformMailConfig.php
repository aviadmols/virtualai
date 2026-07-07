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

    private const CONFIG_TIMEOUT = 'mail.mailers.smtp.timeout';

    private const CONFIG_USERNAME = 'mail.mailers.smtp.username';

    private const CONFIG_PASSWORD = 'mail.mailers.smtp.password';

    private const CONFIG_FROM_ADDRESS = 'mail.from.address';

    private const CONFIG_FROM_NAME = 'mail.from.name';

    // The mailer to switch to once an SMTP host is present.
    private const MAILER_SMTP = 'smtp';

    // UI encryption choices. These are TLS *modes*, not transport schemes — they must be
    // translated (see applyEncryption). "none" means plain (null scheme).
    private const ENCRYPTION_NONE = 'none';

    private const ENCRYPTION_TLS = 'tls';

    private const ENCRYPTION_SSL = 'ssl';

    // The only two schemes Symfony's smtp transport accepts. STARTTLS is negotiated over the
    // plain `smtp` scheme (typically port 587); `smtps` opens implicit TLS from the start (465).
    private const SCHEME_STARTTLS = 'smtp';

    private const SCHEME_IMPLICIT_TLS = 'smtps';

    // Bound the SMTP connect/handshake so a slow/unreachable server fails FAST instead of
    // hanging the request (the club OTP send is on the shopper's request path). Seconds.
    private const SEND_TIMEOUT_SECONDS = 15;

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
            // A hard timeout so a slow/unreachable SMTP server fails fast (see const) rather
            // than hanging the shopper's request; the club send path catches + reports it.
            self::CONFIG_TIMEOUT => self::SEND_TIMEOUT_SECONDS,
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

    /**
     * Map the UI encryption choice onto the smtp transport `scheme`. Symfony's smtp transport
     * accepts ONLY 'smtp'/'smtps' — passing the raw UI value 'tls'/'ssl' throws
     * "The 'tls' scheme is not supported…". So translate: tls => STARTTLS (smtp),
     * ssl => implicit TLS (smtps), none => plain (null). A value that is already a valid scheme
     * passes through; anything unknown falls back to the safe STARTTLS scheme.
     */
    private function applyEncryption(): void
    {
        $encryption = $this->settings->resolve(PlatformSettings::SMTP_ENCRYPTION);

        if (! filled($encryption)) {
            return;
        }

        $scheme = match (strtolower((string) $encryption)) {
            self::ENCRYPTION_NONE => null,
            self::ENCRYPTION_SSL, self::SCHEME_IMPLICIT_TLS => self::SCHEME_IMPLICIT_TLS,
            self::ENCRYPTION_TLS, self::SCHEME_STARTTLS => self::SCHEME_STARTTLS,
            default => self::SCHEME_STARTTLS,
        };

        config([self::CONFIG_SCHEME => $scheme]);
    }
}
