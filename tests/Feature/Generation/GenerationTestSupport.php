<?php

namespace Tests\Feature\Generation;

use App\Domain\Credits\IdempotencyKey;
use App\Domain\Media\MediaStorage;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Shared scaffolding for the generation-pipeline tests: a coherent account / site /
 * end-user / confirmed-product / variant context, a mocked OpenRouter image response,
 * and a faked media disk. No real OpenRouter call and no real S3 ever run here.
 *
 * DETERMINISM (TS-OPENROUTER-003): the generation tests exercise the REAL
 * OpenRouterClient retry/backoff (only the HTTP transport is faked), and that backoff
 * now sleeps via the Sleep facade. bootGenerationEnv() calls Sleep::fake() so no real
 * time passes and the random jitter can never make the suite flaky/slow. Each test
 * also installs a FRESH Http::fake (no fake bleed across `--filter Generation`).
 */
trait GenerationTestSupport
{
    private const OR_BASE = 'https://openrouter.ai/api/v1';

    private const PNG_BYTES = "\x89PNG\r\n\x1a\nTRYON-RESULT-BYTES";

    private const SOURCE_BYTES = "\x89PNG\r\n\x1a\nSHOPPER-PHOTO-BYTES";

    protected function bootGenerationEnv(): void
    {
        $this->seed(AiControlPlaneSeeder::class);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::OR_BASE);
        config()->set('services.openrouter.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');

        // Fake the clock the OpenRouterClient backoff sleeps on, so the real retry path
        // (transient -> bounded retry -> fallback) runs INSTANTLY and DETERMINISTICALLY.
        Sleep::fake();

        // Do NOT pre-install an empty Http::fake() here: in this Laravel version a later
        // Http::fake([...]) does NOT override an earlier empty Http::fake() (the empty
        // catch-all wins and swallows every request). Each test installs its OWN complete
        // fake via a fake* helper before it dispatches; the HTTP factory is reset between
        // tests, so there is no cross-test fake bleed under any `--filter` ordering.
    }

    /**
     * A full context: an active account with the $5 opening grant, a site, a NEW
     * anonymous end-user, a CONFIRMED product, and a variant of that product.
     *
     * @return array{account: Account, site: Site, endUser: EndUser, product: Product, variant: ProductVariant}
     */
    protected function makeContext(array $endUserState = []): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $context = Tenant::run($account, function () use ($account, $site, $endUserState) {
            $endUser = EndUser::factory()->forSite($site)->state($endUserState)->create();
            $product = Product::factory()->forSite($site)->confirmed()->create([
                'name' => 'Red Sneaker',
                'product_type' => 'footwear',
                'main_image_url' => 'https://cdn.example.com/main.jpg',
            ]);
            $variant = ProductVariant::factory()->forProduct($product)->create([
                'options' => ['color' => 'Red', 'size' => 'M'],
                'image_url' => 'https://cdn.example.com/variant.jpg',
            ]);

            return compact('endUser', 'product', 'variant');
        });

        return ['account' => $account, 'site' => $site] + $context;
    }

    /** Create a pending generation with a stored source image, ready for the worker. */
    protected function makePendingGeneration(array $context, string $clientRequestId = 'crq-1'): Generation
    {
        $endUser = $context['endUser'];
        $product = $context['product'];
        $variant = $context['variant'];

        $key = IdempotencyKey::forGeneration(
            accountId: (int) $endUser->account_id,
            siteId: (int) $endUser->site_id,
            endUserId: (int) $endUser->id,
            productId: (int) $product->id,
            variant: (array) $variant->options,
            clientRequestId: $clientRequestId,
        );

        return Tenant::run($context['account'], function () use ($endUser, $product, $variant, $key, $clientRequestId) {
            $generation = Generation::factory()->forContext($endUser, $product, $variant, $clientRequestId)->create([
                'idempotency_key' => $key,
                'status' => Generation::STATUS_PENDING,
                'meta' => [
                    Generation::META_HEIGHT => 178,
                    Generation::META_VARIANT_SNAPSHOT => (array) $variant->options,
                ],
            ]);

            // Store a source photo so loadSourceImage finds it (signed URL path).
            $stored = app(MediaStorage::class)->storeSource(
                (int) $endUser->account_id,
                (int) $endUser->site_id,
                (int) $generation->id,
                self::SOURCE_BYTES,
                'image/png',
            );
            $generation->forceFill(['source_image_path' => $stored->path])->save();

            return $generation;
        });
    }

    /**
     * Mock a successful OpenRouter image response carrying the given cost. The SAME endpoint also
     * serves the Slice E preflight (a json_schema request) — answered with a "usable" verdict so the
     * try-on proceeds; a request WITHOUT response_format is the image generation.
     */
    protected function fakeOpenRouterSuccess(float $costUsd = 0.40): void
    {
        $dataUrl = 'data:image/png;base64,'.base64_encode(self::PNG_BYTES);

        Http::fake([
            self::OR_BASE.'/chat/completions' => function ($request) use ($costUsd, $dataUrl) {
                if ($this->isPreflightRequest($request)) {
                    return $this->preflightResponse(true);
                }

                return Http::response([
                    'id' => 'or-gen-123',
                    'model' => 'google/gemini-2.5-flash-image',
                    'usage' => ['cost' => $costUsd],
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'images' => [['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]],
                        ],
                    ]],
                ], 200);
            },
        ]);
    }

    /** True when the chat/completions request is the Slice E preflight (a strict-JSON call). */
    protected function isPreflightRequest($request): bool
    {
        return isset($request->data()['response_format']);
    }

    /** A preflight verdict response: usable (optionally with a refinement note) or a rejection. */
    protected function preflightResponse(bool $usable, string $reason = '', string $refinement = '')
    {
        $verdict = json_encode(['usable' => $usable, 'reason' => $reason, 'prompt_refinement' => $refinement]);

        return Http::response([
            'id' => 'or-preflight',
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.002],
            'choices' => [['message' => ['role' => 'assistant', 'content' => $verdict]]],
        ], 200);
    }

    /** Mock an OpenRouter 5xx outage (every attempt + fallback fails). */
    protected function fakeOpenRouterOutage(): void
    {
        Http::fake([
            self::OR_BASE.'/chat/completions' => Http::response(['error' => ['message' => 'upstream exploded']], 503),
        ]);
    }

    /** Mock an OpenRouter success body that carries NO usable cost. */
    protected function fakeOpenRouterNoCost(): void
    {
        $dataUrl = 'data:image/png;base64,'.base64_encode(self::PNG_BYTES);

        Http::fake([
            self::OR_BASE.'/chat/completions' => function ($request) use ($dataUrl) {
                if ($this->isPreflightRequest($request)) {
                    return $this->preflightResponse(true);
                }

                return Http::response([
                    'id' => 'or-gen-nocost',
                    'model' => 'google/gemini-2.5-flash-image',
                    // no usage.cost
                    'choices' => [[
                        'message' => ['images' => [['image_url' => ['url' => $dataUrl]]]],
                    ]],
                ], 200);
            },
            // The generation cost-lookup endpoint also returns no cost.
            self::OR_BASE.'/generation*' => Http::response(['data' => []], 200),
        ]);
    }
}
