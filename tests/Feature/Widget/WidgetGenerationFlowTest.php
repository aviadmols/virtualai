<?php

namespace Tests\Feature\Widget;

use App\Domain\Generation\GenerateTryOnJob;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The end-to-end widget generation flow: start (dispatches the worker job, idempotent),
 * poll (pending -> succeeded with a SIGNED expiring result URL only on success), and the
 * add-to-cart funnel event. The signed URL is signed + expiring, never a public path.
 */
final class WidgetGenerationFlowTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    private const ANON = 'anon_flow_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_start_dispatches_one_job_and_returns_a_pending_handle(): void
    {
        Queue::fake();
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->body($ctx));

        $response->assertStatus(201)->assertJson([
            'ok' => true,
            'generation' => ['status' => Generation::STATUS_PENDING],
            'reused' => false,
        ]);
        $this->assertIsInt($response->json('generation.id'));

        Queue::assertPushed(GenerateTryOnJob::class, 1);
    }

    public function test_double_click_same_client_request_id_collapses_to_one_generation(): void
    {
        Queue::fake();
        $ctx = $this->makeSiteContext();
        $body = $this->body($ctx, 'crq-double-click');

        $first = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))->postJson('/widget/v1/generations', $body);
        $second = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))->postJson('/widget/v1/generations', $body);

        $first->assertStatus(201)->assertJson(['reused' => false]);
        // The second click reuses the SAME generation; no second job dispatched.
        $second->assertStatus(201)->assertJson(['reused' => true]);
        $this->assertSame($first->json('generation.id'), $second->json('generation.id'));

        $this->assertSame(1, Tenant::run($ctx['account'], fn () => Generation::query()->count()));
        Queue::assertPushed(GenerateTryOnJob::class, 1);
    }

    public function test_poll_goes_pending_then_succeeded_with_a_signed_expiring_url(): void
    {
        $this->fakeOpenRouterSuccess();
        $ctx = $this->makeSiteContext();

        // Start (the job is dispatched but not yet run).
        Queue::fake();
        $start = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->body($ctx));
        $start->assertStatus(201);
        $generationId = $start->json('generation.id');

        // Poll while pending: no result_url yet.
        $pending = $this->poll($ctx, $generationId);
        $pending->assertOk()->assertJson(['generation' => ['status' => Generation::STATUS_PENDING, 'result_url' => null]]);

        // Run the worker job for real (the money path) -> succeeded, result stored.
        $this->runGeneration($ctx, $generationId);

        // Poll after success: a signed, expiring result URL (never a public/disk path).
        $succeeded = $this->poll($ctx, $generationId);
        $succeeded->assertOk()->assertJson(['generation' => ['status' => Generation::STATUS_SUCCEEDED]]);

        $url = $succeeded->json('generation.result_url');
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('expiration', $url); // Storage::fake signs with an expiry
    }

    public function test_add_to_cart_event_advances_the_lead_funnel(): void
    {
        $this->fakeOpenRouterSuccess();
        $ctx = $this->makeSiteContext();

        // Start + run a generation so the end user is `generated`.
        Queue::fake();
        $start = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $this->body($ctx));
        $generationId = $start->json('generation.id');

        $this->runGeneration($ctx, $generationId);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $this->assertSame(EndUser::STATUS_GENERATED, $endUser->status);

        // Add to cart -> funnel advances generated -> added_to_cart.
        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/events/add-to-cart', [
                'anon_token' => self::ANON,
                'generation_id' => $generationId,
                'variant_id' => $ctx['variant']->id,
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'recorded' => true, 'advanced' => true, 'status' => EndUser::STATUS_ADDED_TO_CART]);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $this->assertSame(EndUser::STATUS_ADDED_TO_CART, $endUser->status);
    }

    public function test_single_sku_product_with_no_variant_generates_against_the_product_image(): void
    {
        // A real ring / single-SKU product: confirmed, but NO variants. The try-on must
        // still run (using the product's main image) — not 422 with variant_mismatch.
        $this->fakeOpenRouterSuccess();

        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create([
            'allowed_origins' => ['https://shop.example.com'],
            'free_generations_before_signup' => 2,
        ]);
        $product = Tenant::run($account, fn () => Product::factory()->forSite($site)->confirmed()->create([
            'name' => 'Plain Gold Ring',
            'product_type' => 'ring',
            'main_image_url' => 'https://cdn.example.com/ring.jpg',
            'source_url' => 'https://shop.example.com/p/ring',
            'source_url_hash' => sha1('https://shop.example.com/p/ring'),
        ]));

        Queue::fake();
        $start = $this->withHeaders($this->widgetHeaders($site, 'https://shop.example.com'))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(),
                'height' => 170,
                'product_id' => $product->id,
                'variant_id' => 0, // single-SKU: no variant to select
                'client_request_id' => 'crq-no-variant',
                'consent' => true,
                'anon_token' => self::ANON,
            ]);

        $start->assertStatus(201)->assertJson(['ok' => true, 'generation' => ['status' => Generation::STATUS_PENDING]]);
        $generationId = $start->json('generation.id');

        // The worker runs the money path against the product image (no variant) -> succeeds.
        $this->runGeneration(['account' => $account, 'site' => $site], $generationId);

        $succeeded = $this->withHeaders($this->widgetHeaders($site, 'https://shop.example.com'))
            ->getJson('/widget/v1/generations/'.$generationId.'?anon_token='.self::ANON);
        $succeeded->assertOk()->assertJson(['generation' => ['status' => Generation::STATUS_SUCCEEDED]]);

        $generation = Tenant::run($account, fn () => Generation::query()->findOrFail($generationId));
        $this->assertNull($generation->product_variant_id);
    }

    public function test_generation_without_height_succeeds_when_the_popup_does_not_ask(): void
    {
        // A jewelry/furniture popup runs with ask_height=false and sends NO height.
        $this->fakeOpenRouterSuccess();
        $ctx = $this->makeSiteContext();
        $body = $this->body($ctx);
        unset($body['height']);

        Queue::fake();
        $start = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $body);
        $start->assertStatus(201)->assertJson(['ok' => true]);

        $generationId = $start->json('generation.id');
        $this->runGeneration($ctx, $generationId);

        $this->poll($ctx, $generationId)
            ->assertOk()->assertJson(['generation' => ['status' => Generation::STATUS_SUCCEEDED]]);
    }

    public function test_a_specified_variant_that_is_not_on_the_product_is_still_a_422(): void
    {
        Queue::fake();
        $ctx = $this->makeSiteContext();
        $body = $this->body($ctx);
        $body['variant_id'] = 999999; // a real id, but not this product's variant

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $body)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => ['code' => 'variant_mismatch']]);

        Queue::assertNothingPushed();
    }

    public function test_oversize_or_unreadable_photo_is_a_typed_422_not_a_500(): void
    {
        Queue::fake();
        $ctx = $this->makeSiteContext();
        $body = $this->body($ctx);
        $body['photo'] = 'data:image/png;base64,@@@not-valid-base64@@@';

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', $body)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => ['code' => 'photo_invalid']]);

        Queue::assertNothingPushed();
    }

    /** Run the worker money path for a started generation (the job auto-binds the tenant). */
    private function runGeneration(array $ctx, int $id): void
    {
        (new GenerateTryOnJob((int) $ctx['account']->id, (int) $ctx['site']->id, $id))->handle();
    }

    private function poll(array $ctx, int $id)
    {
        return $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/generations/'.$id.'?anon_token='.self::ANON);
    }

    private function body(array $ctx, string $crq = 'crq-flow-1'): array
    {
        return [
            'photo' => $this->photoDataUrl(),
            'height' => 174,
            'product_id' => $ctx['product']->id,
            'variant_id' => $ctx['variant']->id,
            'client_request_id' => $crq,
            'consent' => true,
            'anon_token' => self::ANON,
        ];
    }
}
