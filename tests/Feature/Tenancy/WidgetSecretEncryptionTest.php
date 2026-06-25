<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the per-site widget_secret is encrypted at rest with the dedicated
 * TENANT_CREDENTIALS_KEY cipher, round-trips transparently in PHP, and never
 * leaks into serialization.
 */
class WidgetSecretEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_secret_is_ciphertext_at_rest_and_decrypts_in_php(): void
    {
        $account = Account::factory()->create();
        $plaintext = 'super-secret-hmac-value-1234567890';

        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Secret Store',
            'widget_secret' => $plaintext,
        ]));

        // The raw column value must NOT be the plaintext (ciphertext at rest).
        $raw = DB::table('sites')->where('id', $site->id)->value('widget_secret');
        $this->assertNotNull($raw);
        $this->assertNotSame($plaintext, $raw);
        $this->assertStringNotContainsString($plaintext, $raw);

        // A fresh read decrypts transparently back to the plaintext.
        $reloaded = Tenant::run($account, fn () => Site::find($site->id));
        $this->assertSame($plaintext, $reloaded->widget_secret);
    }

    public function test_generated_widget_secret_is_present_and_encrypted(): void
    {
        $account = Account::factory()->create();

        $site = Tenant::run($account, fn () => Site::create(['name' => 'Auto Secret Store']));

        // The creating hook generated a non-empty secret.
        $this->assertNotEmpty($site->widget_secret);

        // And it is ciphertext at rest, not the generated hex plaintext.
        $raw = DB::table('sites')->where('id', $site->id)->value('widget_secret');
        $this->assertNotSame($site->widget_secret, $raw);
    }

    public function test_widget_secret_is_hidden_from_array_serialization(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'Hidden Store']));

        $this->assertArrayNotHasKey('widget_secret', $site->toArray());
        $this->assertArrayNotHasKey('widget_secret', json_decode($site->toJson(), true));
    }
}
