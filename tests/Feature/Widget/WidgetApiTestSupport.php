<?php

namespace Tests\Feature\Widget;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

/**
 * Shared scaffolding for the signed-widget-API tests.
 *
 * Builds a coherent account / site / confirmed-product / variant context under ONE
 * account, fakes the media disk + OpenRouter HTTP (no real S3, no real model call), and
 * gives helpers to call the widget API with valid/invalid auth headers. The opening $5
 * grant means the credit gate passes by default; helpers drain it to test the wall.
 */
trait WidgetApiTestSupport
{
    private const OR_BASE = 'https://openrouter.ai/api/v1';
    private const FIVE_DOLLARS_MICRO = 5_000_000;
    private const PNG_BYTES = "\x89PNG\r\n\x1a\nTRYON-RESULT-BYTES";

    protected function bootWidgetEnv(): void
    {
        $this->seed(AiControlPlaneSeeder::class);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::OR_BASE);
        config()->set('trayon.media.disk', 's3');
        config()->set('widget.hmac.enabled', false);
        Storage::fake('s3');
        Sleep::fake();
        URL::forceRootUrl('https://api.tray-on.test');
    }

    /**
     * A full widget context for a site: an active account with the opening grant, a site
     * with a known allowed origin + site_key, a confirmed product, and a variant.
     *
     * @return array{account: Account, site: Site, product: Product, variant: ProductVariant, origin: string}
     */
    protected function makeSiteContext(array $siteState = [], string $origin = 'https://shop.example.com'): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create($siteState + [
            'allowed_origins' => [$origin],
            'free_generations_before_signup' => 2,
        ]);

        [$product, $variant] = Tenant::run($account, function () use ($site) {
            $product = Product::factory()->forSite($site)->confirmed()->create([
                'name' => 'Red Sneaker',
                'product_type' => 'footwear',
                'source_url' => 'https://shop.example.com/p/red-sneaker',
                'source_url_hash' => sha1('https://shop.example.com/p/red-sneaker'),
                'main_image_url' => 'https://cdn.example.com/main.jpg',
            ]);
            $variant = ProductVariant::factory()->forProduct($product)->create([
                'options' => ['color' => 'Red', 'size' => 'M'],
            ]);

            return [$product, $variant];
        });

        return compact('account', 'site', 'product', 'variant', 'origin');
    }

    /** Valid auth headers (site_key + allow-listed Origin + JSON). */
    protected function widgetHeaders(Site $site, string $origin): array
    {
        return [
            'X-Tray-Site-Key' => $site->site_key,
            'Origin' => $origin,
            'Accept' => 'application/json',
        ];
    }

    /** A valid base64 PNG data-URL the upload accepts. */
    protected function photoDataUrl(): string
    {
        return 'data:image/png;base64,'.base64_encode(self::PNG_BYTES);
    }

    /** Drain the merchant's credits so the credit gate denies (through the ledger writer). */
    protected function drainCredits(Account $account): void
    {
        Tenant::run($account, function () use ($account) {
            app(CreditLedgerService::class)->adjustment(
                $account,
                -self::FIVE_DOLLARS_MICRO,
                IdempotencyKey::forAdjustment($account->id, 'drain'),
                'drain for test',
            );
        });
    }

    /** Mock a successful OpenRouter image response (used when a real generation runs). */
    protected function fakeOpenRouterSuccess(float $costUsd = 0.40): void
    {
        $dataUrl = 'data:image/png;base64,'.base64_encode(self::PNG_BYTES);

        Http::fake([
            self::OR_BASE.'/chat/completions' => Http::response([
                'id' => 'or-gen-123',
                'model' => 'google/gemini-2.5-flash-image',
                'usage' => ['cost' => $costUsd],
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '', 'images' => [['image_url' => ['url' => $dataUrl]]]],
                ]],
            ], 200),
        ]);
    }

    /** Resolve the end user the API created for an anon token (read across the tenant). */
    protected function endUserFor(Account $account, Site $site, string $anonToken): ?EndUser
    {
        return Tenant::run($account, fn () => EndUser::query()
            ->where('site_id', $site->getKey())
            ->where('anon_token', $anonToken)
            ->first());
    }
}
