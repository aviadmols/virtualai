<?php

namespace Tests\Feature\Infra;

use Tests\TestCase;

/**
 * The predeploy guard warns (loudly, non-fatally) when a HOSTED non-production environment
 * (e.g. staging) points MEDIA_DISK at an ephemeral local disk — the recurring "all my uploads
 * vanished on deploy" data loss. Production still hard-fails via the existing branch.
 */
class PredeployCheckTest extends TestCase
{
    /** @var array<string,string> */
    private const REQUIRED_ENV = [
        'APP_KEY' => 'base64:aGVsbG8taGVsbG8taGVsbG8taGVsbG8taGVsbG8=',
        'APP_URL' => 'https://staging.test',
        'TENANT_CREDENTIALS_KEY' => 'tenant-credentials-key-32-bytes!!',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::REQUIRED_ENV as $key => $value) {
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::REQUIRED_ENV) as $key) {
            unset($_SERVER[$key]);
            putenv($key);
        }
        parent::tearDown();
    }

    public function test_staging_warns_but_still_deploys_on_an_ephemeral_media_disk(): void
    {
        config()->set('app.env', 'staging');
        config()->set('trayon.media.disk', 'public');

        $this->artisan('trayon:predeploy-check', ['--skip-disk' => true])
            ->expectsOutputToContain('EPHEMERAL')
            ->assertExitCode(0);
    }

    public function test_staging_warns_when_volume_disk_has_no_mount_path(): void
    {
        config()->set('app.env', 'staging');
        config()->set('trayon.media.disk', 'volume');
        putenv('MEDIA_VOLUME_PATH'); // ensure unset
        unset($_SERVER['MEDIA_VOLUME_PATH']);

        $this->artisan('trayon:predeploy-check', ['--skip-disk' => true])
            ->expectsOutputToContain('MEDIA_VOLUME_PATH')
            ->assertExitCode(0);
    }

    public function test_staging_is_quiet_on_a_persistent_object_disk(): void
    {
        config()->set('app.env', 'staging');
        config()->set('trayon.media.disk', 's3');

        $this->artisan('trayon:predeploy-check', ['--skip-disk' => true])
            ->doesntExpectOutputToContain('WARNING')
            ->assertExitCode(0);
    }

    public function test_a_strict_same_site_cookie_is_refused_because_it_kills_every_shopify_install(): void
    {
        // The Shopify OAuth callback is a cross-site top-level redirect and the install's CSRF
        // wall (the state nonce) lives in the session: 'strict' silently drops the cookie and
        // every install 403s with invalid_state. The deploy must fail LOUDLY instead.
        config()->set('app.env', 'staging');
        config()->set('trayon.media.disk', 's3');
        config()->set('session.same_site', 'strict');

        $this->artisan('trayon:predeploy-check', ['--skip-disk' => true])
            ->expectsOutputToContain('SESSION_SAME_SITE')
            ->assertExitCode(1);
    }

    public function test_the_default_lax_cookie_passes(): void
    {
        config()->set('app.env', 'staging');
        config()->set('trayon.media.disk', 's3');
        config()->set('session.same_site', 'lax');

        $this->artisan('trayon:predeploy-check', ['--skip-disk' => true])
            ->assertExitCode(0);
    }
}
