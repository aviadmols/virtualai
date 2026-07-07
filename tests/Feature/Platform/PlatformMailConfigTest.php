<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\PlatformMailConfig;
use App\Domain\Platform\PlatformSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformMailConfig — the runtime binder that folds the Super-Admin-managed SMTP
 * settings into the live mail config right before a send.
 *
 * Proves: apply() sets mail.default=smtp + mail.mailers.smtp.* + mail.from.* from the
 * stored settings (encryption "none" → null scheme, port cast to int, password bound);
 * with no host configured (DB or env) it is a no-op, so env-only / log-mailer deploys
 * are unaffected; and an env host fallback (no DB row) still activates smtp.
 */
class PlatformMailConfigTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): PlatformSettings
    {
        return app(PlatformSettings::class);
    }

    private function binder(): PlatformMailConfig
    {
        return app(PlatformMailConfig::class);
    }

    private function asSuperAdmin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    private function storeFullSmtp(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, 'smtp.mailhost.test', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_PORT, '2525', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_ENCRYPTION, 'tls', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_USERNAME, 'postmaster', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_PASSWORD, 'smtp-secret-pw');
        $this->settings()->put(PlatformSettings::MAIL_FROM_ADDRESS, 'noreply@shop.test', secret: false);
        $this->settings()->put(PlatformSettings::MAIL_FROM_NAME, 'Shop Mailer', secret: false);
    }

    public function test_apply_binds_the_stored_smtp_config_into_the_live_mailer(): void
    {
        $this->storeFullSmtp();

        $this->binder()->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.mailhost.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        // UI "tls" is a TLS MODE, not a transport scheme — it maps to the smtp (STARTTLS)
        // scheme. Asserting 'tls' here would encode the very bug that made Symfony throw
        // "The 'tls' scheme is not supported".
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertSame('postmaster', config('mail.mailers.smtp.username'));
        $this->assertSame('smtp-secret-pw', config('mail.mailers.smtp.password'));
        $this->assertSame('noreply@shop.test', config('mail.from.address'));
        $this->assertSame('Shop Mailer', config('mail.from.name'));
    }

    public function test_encryption_none_maps_to_a_null_scheme(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, 'smtp.plain.test', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_ENCRYPTION, 'none', secret: false);

        $this->binder()->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertNull(config('mail.mailers.smtp.scheme'));
    }

    public function test_ssl_encryption_maps_to_the_smtps_scheme(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, 'smtp.secure.test', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_PORT, '465', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_ENCRYPTION, 'ssl', secret: false);

        $this->binder()->apply();

        // "ssl" is implicit TLS from the first byte → the smtps scheme.
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_from_falls_back_to_the_smtp_username_when_placeholder(): void
    {
        // The admin configured SMTP but left the From blank → it resolves to the shipped
        // hello@example.com placeholder, which external mailboxes drop (no SPF/DKIM). The binder
        // must swap it for the authenticated SMTP username so club OTPs are actually deliverable.
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, 'smtp.mailhost.test', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_USERNAME, 'sender@gmail.com', secret: false);
        config()->set('mail.from.address', 'hello@example.com'); // the unset/placeholder fallback

        $this->binder()->apply();

        $this->assertSame('sender@gmail.com', config('mail.from.address'));
    }

    public function test_a_real_from_address_is_left_untouched(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, 'smtp.mailhost.test', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_USERNAME, 'sender@gmail.com', secret: false);
        $this->settings()->put(PlatformSettings::MAIL_FROM_ADDRESS, 'club@myshop.co', secret: false);

        $this->binder()->apply();

        // A real, non-placeholder From is respected (not overwritten by the username).
        $this->assertSame('club@myshop.co', config('mail.from.address'));
    }

    public function test_a_localhost_host_is_treated_as_not_configured(): void
    {
        // resolve(SMTP_HOST) falls back to the 127.0.0.1 config default when no DB row exists.
        // The binder must NOT flip the mailer to smtp and silently connect to localhost.
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, '127.0.0.1', secret: false);
        config()->set('mail.default', 'log');

        $this->binder()->apply();

        $this->assertSame('log', config('mail.default'));
    }

    /**
     * The regression guard for the reported "The 'tls' scheme is not supported" error: whatever
     * the admin picks (tls/ssl/none), the resulting config must build a real Symfony transport
     * without throwing. Passing the raw 'tls'/'ssl' as a scheme (the old bug) would throw here.
     */
    public function test_every_ui_encryption_choice_builds_a_valid_transport(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(PlatformSettings::SMTP_HOST, 'smtp.build.test', secret: false);
        $this->settings()->put(PlatformSettings::SMTP_PORT, '587', secret: false);

        foreach (['tls', 'ssl', 'none'] as $encryption) {
            $this->settings()->put(PlatformSettings::SMTP_ENCRYPTION, $encryption, secret: false);

            $this->binder()->apply();
            app()->forgetInstance('mail.manager');

            // Resolving the mailer constructs the EsmtpTransport, which validates the scheme.
            app('mail.manager')->mailer('smtp');
        }

        // Reaching here means all three built without a "scheme not supported" exception.
        $this->addToAssertionCount(1);
    }

    public function test_apply_is_a_noop_when_no_host_is_configured(): void
    {
        config()->set('mail.default', 'log');
        config()->set('mail.mailers.smtp.host', null);

        $this->binder()->apply();

        // Nothing was configured (DB or env) → the env-chosen mailer is untouched.
        $this->assertSame('log', config('mail.default'));
    }

    public function test_env_host_fallback_activates_smtp_without_a_db_row(): void
    {
        // No DB rows; the env fallback (mail.mailers.smtp.host) supplies the host.
        config()->set('mail.default', 'log');
        config()->set('mail.mailers.smtp.host', 'env.smtp.test');

        $this->binder()->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('env.smtp.test', config('mail.mailers.smtp.host'));
    }
}
