<?php

namespace Tests\Feature\ProductImages;

use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Shared scaffolding for the Product Image Studio suite.
 *
 * The default operation resolves to fal (an ASYNC upstream), so the fakes below speak fal's
 * queue: SUBMIT -> {request_id} , STATUS -> IN_QUEUE|COMPLETED , RESULT -> {images:[{url}]}.
 * A single responder closure serves all three (TS-BUILD-004: Http::fake() is install-once —
 * a later fake for the same pattern does NOT replace an earlier one, so the responder, not the
 * fake, is the mutable part).
 *
 * No real fal/OpenRouter call and no real S3 ever run here.
 */
trait ProductImageTestSupport
{
    private const FAL_BASE = 'https://queue.fal.run';

    private const OR_BASE = 'https://openrouter.ai/api/v1';

    private const PNG_BYTES = "\x89PNG\r\n\x1a\nPACKSHOT-RESULT-BYTES";

    private const REQUEST_ID = 'fal-req-123';

    private const MAIN_IMAGE = 'https://cdn.example.com/product-main.jpg';

    /** The fal queue statuses the responder answers with, in order (last one repeats). */
    private array $falStatuses = ['COMPLETED'];

    /** How the result endpoint answers: 'ok' | 'error'. */
    private string $falResultMode = 'ok';

    /** The HTTP status the STATUS endpoint answers with (503 = a transport blip). */
    private int $falStatusCode = 200;

    protected function bootProductImageEnv(): void
    {
        $this->seed(AiControlPlaneSeeder::class);

        config()->set('services.fal.api_key', 'fal-test-key');
        config()->set('services.fal.base_url', self::FAL_BASE);
        config()->set('services.fal.timeout', 30);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', self::OR_BASE);
        config()->set('services.openrouter.timeout', 30);
        config()->set('trayon.media.disk', 's3');

        Storage::fake('s3');
        Sleep::fake();
    }

    /**
     * Install the ONE fal stub (TS-BUILD-004: Http::fake is install-once — the responder state
     * above is the mutable part).
     *
     * The stub closures are SIDE-EFFECT-FREE for counting on purpose: Laravel eagerly maps EVERY
     * matching stub callback and only then takes the first non-null response, so a counter
     * incremented inside a closure fires for stubs whose response is discarded. Call counts are
     * therefore read from Http::recorded() (below), which records the requests that actually
     * went out.
     */
    protected function fakeFal(): void
    {
        Http::fake([
            self::FAL_BASE.'/*/requests/*/status' => function () {
                if ($this->falStatusCode !== 200) {
                    return Http::response(['detail' => 'upstream hiccup'], $this->falStatusCode);
                }

                $status = count($this->falStatuses) > 1
                    ? array_shift($this->falStatuses)
                    : $this->falStatuses[0];

                return Http::response(['status' => $status], 200);
            },

            self::FAL_BASE.'/*/requests/*' => function () {
                if ($this->falResultMode === 'error') {
                    return Http::response(['detail' => 'fal render failed'], 422);
                }

                return Http::response([
                    'images' => [[
                        'url' => 'data:image/png;base64,'.base64_encode(self::PNG_BYTES),
                        'content_type' => 'image/png',
                    ]],
                    'request_id' => self::REQUEST_ID,
                ], 200);
            },

            self::FAL_BASE.'/*' => fn () => Http::response([
                'request_id' => self::REQUEST_ID,
                'status_url' => self::FAL_BASE.'/fal-ai/nano-banana/edit/requests/'.self::REQUEST_ID.'/status',
                'response_url' => self::FAL_BASE.'/fal-ai/nano-banana/edit/requests/'.self::REQUEST_ID,
            ], 200),
        ]);
    }

    /** How many renders were SUBMITTED upstream (a POST that is not a queue-request URL). */
    protected function falSubmitCount(): int
    {
        return Http::recorded(
            fn ($request): bool => $request->method() === 'POST' && ! str_contains($request->url(), '/requests/')
        )->count();
    }

    /** How many STATUS polls went out. */
    protected function falPollCount(): int
    {
        return Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/status'))->count();
    }

    /** The next N polls answer IN_QUEUE, then COMPLETED forever. */
    protected function falPendingThenComplete(int $pendingTicks): void
    {
        $this->falStatuses = array_merge(array_fill(0, $pendingTicks, 'IN_QUEUE'), ['COMPLETED']);
    }

    /** The render finishes but the provider reports a failure on the result endpoint. */
    protected function falFailsUpstream(): void
    {
        $this->falStatuses = ['ERROR'];
        $this->falResultMode = 'error';
    }

    /** A TRANSPORT blip on the status endpoint (the render itself is untouched upstream). */
    protected function falStatusBlip(): void
    {
        $this->falStatusCode = 503;
    }

    /** The blip is over — the next poll answers normally. */
    protected function falStatusRecovers(): void
    {
        $this->falStatusCode = 200;
    }

    /** A coherent account + site + one ACTIVE product carrying a main image. */
    protected function makeShop(array $productState = []): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $product = Tenant::run($account, fn (): Product => Product::factory()->forSite($site)->confirmed()->create([
            'name' => 'Red Sneaker',
            'product_type' => 'footwear',
            'main_image_url' => self::MAIN_IMAGE,
            'images' => ['https://cdn.example.com/alt-1.jpg'],
        ] + $productState));

        return compact('account', 'site', 'product');
    }
}
