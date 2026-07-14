<?php

namespace Tests\Feature\Widget;

use App\Domain\Media\MediaStorage;
use App\Models\EndUser;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /widget/v1/generations/{id}/image — the same-origin RESULT-bytes door (Share).
 *
 * A try-on result is a photo of a stranger's body. This suite is the guard on that: the ONLY
 * party who may read the bytes is the shopper who made them, on the site they made them on,
 * under the bound account. Every other case — another account's site, another shopper on the
 * SAME site, a generation that never succeeded, bytes already purged, no site_key at all —
 * gets the identical flat 404 that confirms nothing.
 */
final class WidgetGenerationImageTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    // === CONSTANTS ===
    private const OWNER_TOKEN = 'anon_owner_1234567890';

    private const STRANGER_TOKEN = 'anon_stranger_0987654321';

    private const RESULT_BYTES = "\x89PNG\r\n\x1a\nTHE-SHOPPERS-BODY";

    private const MIME_PNG = 'image/png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_the_owner_fetches_the_result_bytes_same_origin(): void
    {
        $ctx = $this->makeSiteContext();
        $generation = $this->succeededGeneration($ctx, self::OWNER_TOKEN);

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get($this->imageUrl($generation->id, self::OWNER_TOKEN));

        $response->assertOk();
        $this->assertSame(self::RESULT_BYTES, $response->streamedContent());
        $this->assertStringContainsString(self::MIME_PNG, (string) $response->headers->get('Content-Type'));

        // Private + never stored: no proxy or shared cache may hold a body photo.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        // The widget fetches this cross-origin from the storefront -> it must be CORS-readable.
        $this->assertSame($ctx['origin'], $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * THE LEAK THAT MATTERS: two shoppers on the SAME site, so the account and the site both
     * match and only the end_user_id stands between a stranger and a photo of someone's body.
     */
    public function test_a_shopper_cannot_fetch_another_shoppers_image_on_the_same_site(): void
    {
        $ctx = $this->makeSiteContext();
        $ownerGeneration = $this->succeededGeneration($ctx, self::OWNER_TOKEN);

        // The stranger is a REAL end user on the same site (so the token resolves) — the only
        // thing that may stop them is that the generation is not theirs.
        Tenant::run($ctx['account'], fn () => EndUser::factory()
            ->forSite($ctx['site'])
            ->create(['anon_token' => self::STRANGER_TOKEN]));

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get($this->imageUrl($ownerGeneration->id, self::STRANGER_TOKEN));

        $response->assertNotFound()->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);
        $this->assertStringNotContainsString(self::RESULT_BYTES, $response->getContent() ?: '');
    }

    /**
     * The POLL door shares the ownership rule, so it is pinned by the SAME stranger. (The old
     * poll test only proved an UNKNOWN token is refused — a real second shopper on the site is
     * the case that actually leaks, and it is what both doors are now held to.)
     */
    public function test_a_real_second_shopper_cannot_poll_another_shoppers_generation(): void
    {
        $ctx = $this->makeSiteContext();
        $ownerGeneration = $this->succeededGeneration($ctx, self::OWNER_TOKEN);

        Tenant::run($ctx['account'], fn () => EndUser::factory()
            ->forSite($ctx['site'])
            ->create(['anon_token' => self::STRANGER_TOKEN]));

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/generations/'.$ownerGeneration->id.'?anon_token='.self::STRANGER_TOKEN);

        $response->assertNotFound()->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);
        // Not even a signed URL — the stranger gets no handle on the bytes by any route.
        $this->assertNull($response->json('generation.result_url'));
    }

    public function test_site_b_shopper_cannot_fetch_site_a_generation_image(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');

        $generationA = $this->succeededGeneration($a, self::OWNER_TOKEN);

        // Site B's shopper carries the SAME anon_token string (tokens are per-site, so a
        // collision is legal) and asks for site A's generation id, with site B's site_key.
        Tenant::run($b['account'], fn () => EndUser::factory()
            ->forSite($b['site'])
            ->create(['anon_token' => self::OWNER_TOKEN]));

        $response = $this->withHeaders($this->widgetHeaders($b['site'], $b['origin']))
            ->get($this->imageUrl($generationA->id, self::OWNER_TOKEN));

        $response->assertNotFound()->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);
        $this->assertStringNotContainsString(self::RESULT_BYTES, $response->getContent() ?: '');

        // And the owner still reads their own (the guard blocks the stranger, not the shopper).
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))
            ->get($this->imageUrl($generationA->id, self::OWNER_TOKEN))
            ->assertOk();
    }

    public function test_a_non_succeeded_generation_returns_404_even_for_its_owner(): void
    {
        $ctx = $this->makeSiteContext();

        $generation = Tenant::run($ctx['account'], function () use ($ctx) {
            $endUser = EndUser::factory()->forSite($ctx['site'])->create(['anon_token' => self::OWNER_TOKEN]);

            // Pending: no result bytes exist yet — there is nothing honest to stream.
            return Generation::factory()
                ->forContext($endUser, $ctx['product'], $ctx['variant'], 'crq_pending')
                ->create();
        });

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get($this->imageUrl($generation->id, self::OWNER_TOKEN))
            ->assertNotFound()
            ->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);
    }

    /**
     * STATUS is the authority, not the presence of bytes. The pipeline stores the result BEFORE
     * it charges, so bytes can exist on a row that never reached `succeeded` (a failed finalize,
     * a cancel). Those bytes are not a look the shopper was ever shown — the door must refuse
     * them on the STATUS, not merely because the path happens to be empty.
     */
    public function test_stored_bytes_on_a_non_succeeded_generation_are_still_refused(): void
    {
        $ctx = $this->makeSiteContext();

        $generation = Tenant::run($ctx['account'], function () use ($ctx) {
            $endUser = EndUser::factory()->forSite($ctx['site'])->create(['anon_token' => self::OWNER_TOKEN]);

            $generation = Generation::factory()
                ->forContext($endUser, $ctx['product'], $ctx['variant'], 'crq_stored_not_succeeded')
                ->processing()
                ->create();

            $stored = app(MediaStorage::class)->storeResult(
                (int) $ctx['account']->id,
                (int) $ctx['site']->id,
                (int) $generation->id,
                self::RESULT_BYTES,
                self::MIME_PNG,
            );

            $generation->forceFill(['result_image_path' => $stored->path])->save();

            return $generation;
        });

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get($this->imageUrl($generation->id, self::OWNER_TOKEN));

        $response->assertNotFound()->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);
        $this->assertStringNotContainsString(self::RESULT_BYTES, $response->getContent() ?: '');
    }

    public function test_a_purged_result_returns_404_not_a_500(): void
    {
        $ctx = $this->makeSiteContext();
        $generation = $this->succeededGeneration($ctx, self::OWNER_TOKEN);

        // Retention purged the bytes; the row survives. The door must not blow up.
        app(MediaStorage::class)->delete($generation->result_image_path);

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get($this->imageUrl($generation->id, self::OWNER_TOKEN))
            ->assertNotFound()
            ->assertJson(['ok' => false, 'error' => ['code' => 'generation_not_found']]);
    }

    public function test_an_unknown_anon_token_returns_404(): void
    {
        $ctx = $this->makeSiteContext();
        $generation = $this->succeededGeneration($ctx, self::OWNER_TOKEN);

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get($this->imageUrl($generation->id, 'anon_never_seen_before_00'))
            ->assertNotFound();

        // No claim of ownership at all is the same flat 404.
        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->get('/widget/v1/generations/'.$generation->id.'/image')
            ->assertNotFound();
    }

    /** The route lives INSIDE the widget-auth group: no site_key -> 401, never bytes. */
    public function test_the_image_route_is_behind_the_widget_auth_floor(): void
    {
        $ctx = $this->makeSiteContext();
        $generation = $this->succeededGeneration($ctx, self::OWNER_TOKEN);

        $this->withHeaders(['Origin' => $ctx['origin'], 'Accept' => 'application/json'])
            ->get($this->imageUrl($generation->id, self::OWNER_TOKEN))
            ->assertUnauthorized()
            ->assertJson(['ok' => false, 'error' => ['code' => 'unknown_site']]);

        // A valid site_key from a NON-allow-listed origin is a 403 (the Origin allow-list).
        $this->withHeaders([
            'X-Tray-Site-Key' => $ctx['site']->site_key,
            'Origin' => 'https://evil.example.com',
            'Accept' => 'application/json',
        ])->get($this->imageUrl($generation->id, self::OWNER_TOKEN))->assertForbidden();
    }

    /** The image URL the widget's Share path calls. */
    private function imageUrl(int $generationId, string $anonToken): string
    {
        return '/widget/v1/generations/'.$generationId.'/image?anon_token='.urlencode($anonToken);
    }

    /** A SUCCEEDED generation with real stored result bytes, owned by this site's anon_token. */
    private function succeededGeneration(array $ctx, string $anonToken): Generation
    {
        return Tenant::run($ctx['account'], function () use ($ctx, $anonToken): Generation {
            $endUser = EndUser::factory()->forSite($ctx['site'])->create(['anon_token' => $anonToken]);

            $generation = Generation::factory()
                ->forContext($endUser, $ctx['product'], $ctx['variant'], 'crq_'.substr(sha1($anonToken), 0, 12))
                ->create();

            $stored = app(MediaStorage::class)->storeResult(
                (int) $ctx['account']->id,
                (int) $ctx['site']->id,
                (int) $generation->id,
                self::RESULT_BYTES,
                self::MIME_PNG,
            );

            $generation->forceFill([
                'status' => Generation::STATUS_SUCCEEDED,
                'result_image_path' => $stored->path,
            ])->save();

            return $generation;
        });
    }
}
