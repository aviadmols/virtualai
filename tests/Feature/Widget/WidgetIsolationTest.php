<?php

namespace Tests\Feature\Widget;

use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation across the widget API (release-blocker class): a request for site A
 * binds account A; end user B can never read A's generations or gallery; bootstrap for
 * site A never leaks site B's product; back-to-back two-site requests don't leak tenant.
 */
final class WidgetIsolationTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_bootstrap_for_site_a_never_returns_site_b_product(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        // Site B has a product at the SAME url, but under a different account.
        $b = $this->makeSiteContext([], 'https://b.example.com');

        $url = 'https://shop.example.com/p/red-sneaker';

        $response = $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))
            ->getJson('/widget/v1/bootstrap?url='.urlencode($url));

        $response->assertOk();
        // A's bootstrap returns A's product id, never B's.
        $this->assertSame($a['product']->id, $response->json('product.id'));
        $this->assertNotSame($b['product']->id, $response->json('product.id'));
    }

    public function test_end_user_b_cannot_read_end_user_a_generation(): void
    {
        $ctx = $this->makeSiteContext();
        $this->fakeOpenRouterSuccess();

        // End user A starts a generation.
        $start = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(),
                'height' => 178,
                'product_id' => $ctx['product']->id,
                'variant_id' => $ctx['variant']->id,
                'client_request_id' => 'crq-iso-1',
                'consent' => true,
                'anon_token' => 'anon_userA_1234567890',
            ]);
        $start->assertStatus(201);
        $generationId = $start->json('generation.id');

        // End user B polls A's generation id with B's OWN anon_token -> not found (scoped).
        $poll = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/generations/'.$generationId.'?anon_token=anon_userB_0987654321');

        $poll->assertStatus(404)->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);

        // And A can read its own.
        $self = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/generations/'.$generationId.'?anon_token=anon_userA_1234567890');
        $self->assertOk()->assertJson(['ok' => true, 'generation' => ['id' => $generationId]]);
    }

    public function test_a_request_for_site_a_binds_account_a_only(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');
        $this->fakeOpenRouterSuccess();

        // Generate under site A.
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(),
                'height' => 170,
                'product_id' => $a['product']->id,
                'variant_id' => $a['variant']->id,
                'client_request_id' => 'crq-a',
                'consent' => true,
                'anon_token' => 'anon_a_1234567890',
            ])->assertStatus(201);

        // The generation row lands on account A, never B.
        $aCount = Tenant::run($a['account'], fn () => Generation::query()->count());
        $bCount = Tenant::run($b['account'], fn () => Generation::query()->count());

        $this->assertSame(1, $aCount);
        $this->assertSame(0, $bCount);
    }

    public function test_back_to_back_two_site_requests_do_not_leak_tenant(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');
        $this->fakeOpenRouterSuccess();

        // Request 1: site A.
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(), 'height' => 160,
                'product_id' => $a['product']->id, 'variant_id' => $a['variant']->id,
                'client_request_id' => 'crq-a2', 'consent' => true, 'anon_token' => 'anon_a_2222222222',
            ])->assertStatus(201);

        // Request 2 (same process): site B. Must bind B, not the leftover A.
        $this->withHeaders($this->widgetHeaders($b['site'], $b['origin']))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(), 'height' => 165,
                'product_id' => $b['product']->id, 'variant_id' => $b['variant']->id,
                'client_request_id' => 'crq-b2', 'consent' => true, 'anon_token' => 'anon_b_2222222222',
            ])->assertStatus(201);

        // Each account has exactly one generation, on its own books.
        $this->assertSame(1, Tenant::run($a['account'], fn () => Generation::query()->count()));
        $this->assertSame(1, Tenant::run($b['account'], fn () => Generation::query()->count()));

        // The tenant context is clear after the request lifecycle (never ambient).
        $this->assertFalse(Tenant::check());
    }

    public function test_gallery_is_scoped_to_the_end_user(): void
    {
        $ctx = $this->makeSiteContext();
        $this->fakeOpenRouterSuccess();

        // A succeeded generation owned by end user A.
        $start = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(), 'height' => 172,
                'product_id' => $ctx['product']->id, 'variant_id' => $ctx['variant']->id,
                'client_request_id' => 'crq-gal', 'consent' => true, 'anon_token' => 'anon_galA_1234567890',
            ]);
        $start->assertStatus(201);
        // Mark it succeeded with a stored result (simulate the worker finishing).
        Tenant::run($ctx['account'], function () use ($start, $ctx) {
            $generation = Generation::query()->findOrFail($start->json('generation.id'));
            $stored = app(\App\Domain\Media\MediaStorage::class)->storeResult(
                (int) $ctx['account']->id, (int) $ctx['site']->id, (int) $generation->id, 'RESULT-BYTES', 'image/png',
            );
            $generation->forceFill(['status' => Generation::STATUS_SUCCEEDED, 'result_image_path' => $stored->path])->save();
        });

        // End user A sees one tile; end user B sees an empty gallery.
        $galleryA = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/gallery?anon_token=anon_galA_1234567890');
        $galleryA->assertOk();
        $this->assertCount(1, $galleryA->json('items'));
        $this->assertNotEmpty($galleryA->json('items.0.result_url'));

        $galleryB = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/gallery?anon_token=anon_galB_0987654321');
        $galleryB->assertOk();
        $this->assertCount(0, $galleryB->json('items'));
    }
}
