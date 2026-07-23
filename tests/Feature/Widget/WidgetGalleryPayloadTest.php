<?php

namespace Tests\Feature\Widget;

use App\Domain\Media\MediaStorage;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gallery + poll payload now carries each look's PRODUCT + VARIANT (name, price and the
 * add-to-cart target), so the widget can switch the shown product and enable add-to-cart when
 * a shopper taps a past look of a different product. It stays secret-free and tenant-scoped.
 */
final class WidgetGalleryPayloadTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    /** Start a try-on for the context product/variant and force it succeeded. @return int generation id */
    private function succeededLook(array $ctx, string $anonToken): int
    {
        $this->fakeOpenRouterSuccess();

        $start = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson('/widget/v1/generations', [
                'photo' => $this->photoDataUrl(), 'height' => 170,
                'product_id' => $ctx['product']->id, 'variant_id' => $ctx['variant']->id,
                'client_request_id' => 'crq-'.$anonToken, 'consent' => true, 'anon_token' => $anonToken,
            ]);
        $start->assertStatus(201);

        Tenant::run($ctx['account'], function () use ($start, $ctx) {
            $generation = Generation::query()->findOrFail($start->json('generation.id'));
            $stored = app(MediaStorage::class)->storeResult(
                (int) $ctx['account']->id, (int) $ctx['site']->id, (int) $generation->id, 'RESULT-BYTES', 'image/png',
            );
            $generation->forceFill(['status' => Generation::STATUS_SUCCEEDED, 'result_image_path' => $stored->path])->save();
        });

        return (int) $start->json('generation.id');
    }

    public function test_gallery_item_carries_the_product_and_variant(): void
    {
        $ctx = $this->makeSiteContext();
        $this->succeededLook($ctx, 'anon_pay_1234567890');

        $gallery = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/gallery?anon_token=anon_pay_1234567890');

        $gallery->assertOk();
        $item = $gallery->json('items.0');

        $this->assertNotEmpty($item['result_url']);
        // Product name + price for the header.
        $this->assertSame((int) $ctx['product']->id, $item['product']['id']);
        $this->assertSame('Red Sneaker', $item['product']['name']);
        $this->assertArrayHasKey('price_minor', $item['product']);
        $this->assertArrayHasKey('currency', $item['product']);
        // The variant = the add-to-cart target the widget's cart layer needs.
        $this->assertSame((int) $ctx['variant']->id, $item['variant']['id']);
        $this->assertSame(['color' => 'Red', 'size' => 'M'], $item['variant']['options']);
        $this->assertArrayHasKey('external_id', $item['variant']);
        $this->assertArrayHasKey('available', $item['variant']);
    }

    public function test_poll_payload_carries_the_same_product_and_variant(): void
    {
        $ctx = $this->makeSiteContext();
        $id = $this->succeededLook($ctx, 'anon_poll_1234567890');

        $poll = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/generations/'.$id.'?anon_token=anon_poll_1234567890');

        $poll->assertOk();
        $gen = $poll->json('generation');
        $this->assertSame((int) $ctx['product']->id, $gen['product']['id']);
        $this->assertSame((int) $ctx['variant']->id, $gen['variant']['id']);
    }

    public function test_the_payload_leaks_no_secret_or_internal_field(): void
    {
        $ctx = $this->makeSiteContext();
        $this->succeededLook($ctx, 'anon_leak_1234567890');

        $item = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->getJson('/widget/v1/gallery?anon_token=anon_leak_1234567890')
            ->json('items.0');

        // No cost, ledger, account, site, or raw paths ride out — on the item or its product/variant.
        foreach (['account_id', 'site_id', 'result_image_path', 'actual_cost_micro_usd', 'charge_ledger_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $item);
            $this->assertArrayNotHasKey($forbidden, $item['product']);
            $this->assertArrayNotHasKey($forbidden, $item['variant']);
        }
    }
}
