<?php

namespace Tests\Feature\Filament\Platform;

use App\Domain\Platform\PlatformSettings;
use App\Filament\Platform\Pages\Settings;
use App\Mail\PlatformTestMail;
use App\Models\PlatformSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform Settings page — render, save, and the write-only secret contract.
 *
 * Proves the page renders for a super-admin, a key entered in the form is stored (and
 * then resolves as the effective value), and a STORED secret is never preloaded into
 * the form/browser (write-only — the key never reaches the browser).
 */
class PlatformSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test(Settings::class)->assertOk();
    }

    public function test_saving_stores_the_openrouter_key(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['openrouter_api_key' => 'sk-or-entered-in-ui'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'sk-or-entered-in-ui',
            app(PlatformSettings::class)->resolve(PlatformSettings::OPENROUTER_API_KEY),
        );
    }

    public function test_a_stored_secret_is_write_only_never_preloaded(): void
    {
        PlatformSetting::create([
            'key' => PlatformSettings::OPENROUTER_API_KEY,
            'value' => 'sk-or-already-stored',
            'is_secret' => true,
        ]);

        Livewire::test(Settings::class)
            ->assertOk()
            ->assertDontSee('sk-or-already-stored')
            ->assertFormSet(['openrouter_api_key' => null]);
    }

    public function test_blank_save_keeps_the_existing_value(): void
    {
        PlatformSetting::create([
            'key' => PlatformSettings::OPENROUTER_API_KEY,
            'value' => 'sk-or-keep-me',
            'is_secret' => true,
        ]);

        // Saving with the field left blank must NOT wipe the stored key.
        Livewire::test(Settings::class)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-or-keep-me', app(PlatformSettings::class)->resolve(PlatformSettings::OPENROUTER_API_KEY));
    }

    public function test_visible_smtp_fields_persist_and_hydrate(): void
    {
        Livewire::test(Settings::class)
            ->fillForm([
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_encryption' => 'tls',
                'smtp_username' => 'postmaster',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Tray On',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(PlatformSettings::class);
        $this->assertSame('smtp.example.com', $settings->resolve(PlatformSettings::SMTP_HOST));
        $this->assertSame('587', $settings->resolve(PlatformSettings::SMTP_PORT));
        $this->assertSame('tls', $settings->resolve(PlatformSettings::SMTP_ENCRYPTION));
        $this->assertSame('noreply@example.com', $settings->resolve(PlatformSettings::MAIL_FROM_ADDRESS));

        // A fresh mount hydrates the VISIBLE values so the admin sees them.
        Livewire::test(Settings::class)
            ->assertFormSet([
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_encryption' => 'tls',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Tray On',
            ]);
    }

    public function test_blank_visible_field_clears_and_falls_back_to_env(): void
    {
        config()->set('mail.mailers.smtp.host', 'env.smtp.test');
        app(PlatformSettings::class)->put(PlatformSettings::SMTP_HOST, 'db.smtp.test', secret: false);

        // Clearing the field on save removes the DB row → env fallback wins again.
        Livewire::test(Settings::class)
            ->fillForm(['smtp_host' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(app(PlatformSettings::class)->isStoredInDb(PlatformSettings::SMTP_HOST));
        $this->assertSame('env.smtp.test', app(PlatformSettings::class)->resolve(PlatformSettings::SMTP_HOST));
    }

    public function test_smtp_password_persists_encrypted_and_is_write_only(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['smtp_password' => 'super-smtp-secret'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Stored encrypted at rest (never the plaintext in the column).
        $raw = DB::table('platform_settings')->where('key', PlatformSettings::SMTP_PASSWORD)->value('value');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('super-smtp-secret', (string) $raw);

        // Marked secret, and the cast decrypts it back for the transport.
        $row = PlatformSetting::query()->where('key', PlatformSettings::SMTP_PASSWORD)->first();
        $this->assertTrue((bool) $row->is_secret);
        $this->assertSame('super-smtp-secret', app(PlatformSettings::class)->resolve(PlatformSettings::SMTP_PASSWORD));

        // Write-only: never preloaded to the browser/form.
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertDontSee('super-smtp-secret')
            ->assertFormSet(['smtp_password' => null]);
    }

    public function test_send_test_email_uses_the_configured_transport(): void
    {
        Mail::fake();
        app(PlatformSettings::class)->put(PlatformSettings::SMTP_HOST, 'smtp.example.com', secret: false);

        Livewire::test(Settings::class)
            ->callAction('sendTestEmail', ['recipient' => 'admin@example.com'])
            ->assertHasNoActionErrors();

        Mail::assertSent(PlatformTestMail::class, fn (PlatformTestMail $mail) => $mail->hasTo('admin@example.com'));
    }
}
