<?php

namespace Tests\Feature\Platform;

use App\Domain\Ai\OpenRouterClient;
use App\Domain\Platform\PlatformSettings;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * End-to-end proof that the OpenRouter bearer key comes from the platform Settings
 * page (encrypted DB row) when set, and from the env var otherwise — so changing the
 * key in the UI takes effect with no redeploy.
 */
class OpenRouterKeyFromSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://openrouter.ai/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.key', 'env-fallback-key');
        config()->set('services.openrouter.base_url', self::BASE);
        config()->set('services.openrouter.timeout', 30);
        Sleep::fake();

        Http::fake([
            self::BASE.'/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'm', 'usage' => ['cost' => 0.0], 'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);
    }

    private function callChat(): void
    {
        app(OpenRouterClient::class)->chat(
            ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            'product_scan',
        );
    }

    public function test_db_managed_key_is_used_as_the_bearer(): void
    {
        PlatformSetting::create([
            'key' => PlatformSettings::OPENROUTER_API_KEY,
            'value' => 'sk-or-db-managed-key',
            'is_secret' => true,
        ]);

        $this->callChat();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-or-db-managed-key'));
    }

    public function test_env_key_is_used_when_no_db_setting(): void
    {
        $this->callChat();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer env-fallback-key'));
    }

    public function test_a_placeholder_key_fails_fast_with_a_clear_message_and_no_http_call(): void
    {
        // The shipped default OPENROUTER_API_KEY=REPLACE_WITH_REAL_OPENROUTER_KEY must NOT
        // reach the provider (it returns an opaque 404); fail fast with a readable cause.
        config()->set('services.openrouter.key', 'REPLACE_WITH_REAL_OPENROUTER_KEY');

        try {
            $this->callChat();
            $this->fail('Expected an OpenRouterException for the placeholder key.');
        } catch (\App\Domain\Ai\OpenRouterException $e) {
            $this->assertSame(\App\Domain\Ai\OpenRouterException::CODE_BAD_REQUEST, $e->errorCode);
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        Http::assertNothingSent();

        // And the setup checklist must report it as NOT configured (no false "✓").
        $this->assertFalse(app(PlatformSettings::class)->isConfigured(PlatformSettings::OPENROUTER_API_KEY));
        $this->assertTrue(PlatformSettings::looksLikePlaceholder('REPLACE_WITH_REAL_OPENROUTER_KEY'));
        $this->assertFalse(PlatformSettings::looksLikePlaceholder('sk-or-v1-real'));
    }
}
