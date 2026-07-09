<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\PlatformSettings;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\TenantCredentialsCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlatformSettings — the control-plane secret store.
 *
 * Proves the DB value wins over the env fallback (so the Settings page takes effect),
 * an unset value transparently falls back to the env var (env-only deploys keep
 * working), secrets are ENCRYPTED at rest, and writes are super-admin only (a denied
 * write persists nothing).
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = PlatformSettings::OPENROUTER_API_KEY;
    private const CONFIG_KEY = 'services.openrouter.key';

    private function settings(): PlatformSettings
    {
        return app(PlatformSettings::class);
    }

    private function asSuperAdmin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_db_value_wins_over_the_env_fallback(): void
    {
        config()->set(self::CONFIG_KEY, 'env-key');
        $this->asSuperAdmin();

        $this->settings()->put(self::KEY, 'db-key');

        $this->assertSame('db-key', $this->settings()->resolve(self::KEY));
    }

    public function test_resolve_falls_back_to_env_when_unset(): void
    {
        config()->set(self::CONFIG_KEY, 'env-key');

        $this->assertSame('env-key', $this->settings()->resolve(self::KEY));
        $this->assertFalse($this->settings()->isStoredInDb(self::KEY));
        $this->assertTrue($this->settings()->isConfigured(self::KEY));
    }

    public function test_blank_clears_the_row_and_falls_back_to_env(): void
    {
        config()->set(self::CONFIG_KEY, 'env-key');
        $this->asSuperAdmin();

        $this->settings()->put(self::KEY, 'db-key');
        $this->assertSame('db-key', $this->settings()->resolve(self::KEY));

        $this->settings()->put(self::KEY, null);
        $this->assertFalse($this->settings()->isStoredInDb(self::KEY));
        $this->assertSame('env-key', $this->settings()->resolve(self::KEY));
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(self::KEY, 'sk-or-super-secret');

        // The raw column is ciphertext, never the plaintext.
        $raw = DB::table('platform_settings')->where('key', self::KEY)->value('value');
        $this->assertNotNull($raw);
        $this->assertNotSame('sk-or-super-secret', $raw);
        $this->assertStringNotContainsString('sk-or-super-secret', (string) $raw);

        // The cast decrypts it back transparently.
        $this->assertSame('sk-or-super-secret', PlatformSetting::query()->where('key', self::KEY)->first()->value);
    }

    public function test_a_saved_secret_survives_a_fresh_read(): void
    {
        $this->asSuperAdmin();
        $this->settings()->put(self::KEY, 'sk-or-persist');

        // A brand-new request (fresh cipher + resolver instance) still reads the saved secret.
        TenantCredentialsCipher::flush();
        $this->assertSame('sk-or-persist', app(PlatformSettings::class)->resolve(self::KEY));
    }

    public function test_a_rotated_credentials_key_degrades_to_env_not_a_crash(): void
    {
        config()->set('trayon.credentials_key', 'base64:'.base64_encode(random_bytes(32)));
        TenantCredentialsCipher::flush();
        $this->asSuperAdmin();
        $this->settings()->put(self::KEY, 'sk-or-db');
        $this->assertSame('sk-or-db', $this->settings()->resolve(self::KEY));

        // The env key rotates across a deploy — the old ciphertext can no longer be decrypted.
        config()->set(self::CONFIG_KEY, 'env-fallback-key');
        config()->set('trayon.credentials_key', 'base64:'.base64_encode(random_bytes(32)));
        TenantCredentialsCipher::flush();

        // No 500: the un-decryptable DB value degrades to null and resolve() falls back to the env
        // var — so keys set as env vars are NEVER lost even if the credentials key rotates.
        $this->assertSame('env-fallback-key', $this->settings()->resolve(self::KEY));
    }

    public function test_a_non_super_admin_cannot_write_and_persists_nothing(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        try {
            $this->settings()->put(self::KEY, 'db-key');
            $this->fail('Expected PlatformAccessRequiredException');
        } catch (PlatformAccessRequiredException) {
            // expected
        }

        $this->assertSame(0, PlatformSetting::query()->count());
    }

    public function test_an_unauthenticated_caller_cannot_write(): void
    {
        Auth::logout();

        $this->expectException(PlatformAccessRequiredException::class);
        $this->settings()->put(self::KEY, 'db-key');
    }
}
