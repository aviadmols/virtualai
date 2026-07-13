<?php

namespace Tests\Feature\Shopify;

use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared fixtures for the Phase-3 product-sync suite: a connected shop, an Admin-API
 * product node in the exact GraphQL shape the mapper reads, and Http fakes for the
 * catalog / single-product / count / search documents.
 *
 * Nothing here touches a real Shopify store; every call is faked at the HTTP boundary.
 */
trait ShopifyProductTestSupport
{
    // === CONSTANTS ===
    protected const SHOP = 'trayon-test.myshopify.com';

    protected const GID_A = 'gid://shopify/Product/1001';

    protected const GID_B = 'gid://shopify/Product/1002';

    protected const VARIANT_A1 = 'gid://shopify/ProductVariant/2001';

    protected const VARIANT_A2 = 'gid://shopify/ProductVariant/2002';

    /** @return array{0: Account, 1: Site, 2: ShopifyConnection} */
    protected function connectedShop(string $shop = self::SHOP): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create(['platform' => Site::PLATFORM_SHOPIFY]);

        $connection = Tenant::run($account, fn (): ShopifyConnection => ShopifyConnection::factory()
            ->forSite($site)
            ->create(['shop_domain' => $shop]));

        return [$account, $site, $connection];
    }

    /** The Admin GraphQL endpoint pattern every fake matches (any shop). */
    protected function endpoint(string $shop = self::SHOP): string
    {
        return 'https://'.$shop.'/admin/api/*/graphql.json';
    }

    /** The swappable responder behind the ONE registered Http stub. */
    protected ?\Closure $shopifyResponder = null;

    protected bool $shopifyStubRegistered = false;

    /**
     * Register EXACTLY ONE Http stub and swap the behaviour behind it.
     *
     * TS-BUILD-004: a second Http::fake([...]) does NOT replace the first — the factory
     * MERGES stub callbacks and the FIRST match wins. So a test that fakes a catalog and
     * later fakes a different catalog would silently keep serving the first one. One stub
     * + a swappable responder makes re-stubbing actually work.
     */
    protected function respondWith(\Closure $responder): void
    {
        $this->shopifyResponder = $responder;

        if ($this->shopifyStubRegistered) {
            return;
        }

        $this->shopifyStubRegistered = true;

        Http::fake([
            '*/admin/api/*/graphql.json' => fn (Request $request) => ($this->shopifyResponder)($request),
        ]);
    }

    /**
     * One Admin-API Product node. Defaults describe a two-variant clothing product with
     * a real Material axis (so materials reach the try-on prompt).
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function productNode(array $overrides = []): array
    {
        return array_replace([
            'id' => self::GID_A,
            'handle' => 'merino-crew',
            'title' => 'Merino Crew Sweater',
            'description' => 'A soft merino crew neck.',
            'descriptionHtml' => '<p>A soft merino crew neck.</p>',
            'productType' => 'Sweaters',
            'vendor' => 'Northstead',
            'tags' => ['knitwear'],
            'status' => 'ACTIVE',
            'onlineStoreUrl' => 'https://northstead.com/products/merino-crew',
            'featuredImage' => ['url' => 'https://cdn.shopify.com/merino-1.jpg', 'altText' => null],
            'images' => ['nodes' => [
                ['url' => 'https://cdn.shopify.com/merino-1.jpg', 'altText' => null],
                ['url' => 'https://cdn.shopify.com/merino-2.jpg', 'altText' => null],
            ]],
            'options' => [
                ['name' => 'Size', 'position' => 1, 'values' => ['S', 'M']],
                ['name' => 'Material', 'position' => 2, 'values' => ['Merino wool']],
            ],
            'priceRangeV2' => [
                'minVariantPrice' => ['amount' => '49.90', 'currencyCode' => 'EUR'],
                'maxVariantPrice' => ['amount' => '59.90', 'currencyCode' => 'EUR'],
            ],
            'variants' => ['nodes' => [
                [
                    'id' => self::VARIANT_A1,
                    'title' => 'S / Merino wool',
                    'sku' => 'MC-S',
                    'position' => 1,
                    'availableForSale' => true,
                    'price' => '49.90',
                    'compareAtPrice' => '69.90',
                    'selectedOptions' => [
                        ['name' => 'Size', 'value' => 'S'],
                        ['name' => 'Material', 'value' => 'Merino wool'],
                    ],
                    'image' => ['url' => 'https://cdn.shopify.com/merino-s.jpg'],
                ],
                [
                    'id' => self::VARIANT_A2,
                    'title' => 'M / Merino wool',
                    'sku' => 'MC-M',
                    'position' => 2,
                    'availableForSale' => true,
                    'price' => '59.90',
                    'compareAtPrice' => null,
                    'selectedOptions' => [
                        ['name' => 'Size', 'value' => 'M'],
                        ['name' => 'Material', 'value' => 'Merino wool'],
                    ],
                    'image' => null,
                ],
            ]],
        ], $overrides);
    }

    /**
     * Fake a catalog walk. $pages is a list of [nodes[], hasNextPage, endCursor]; the
     * fake picks the page matching the request's `after` cursor, so the resume path is
     * exercised for real (not just replayed in order).
     *
     * The `productsCount` document (StartShopifySync's soft-cap probe, which now runs
     * BEFORE any walk is opened) is answered from the same fixture: the store holds
     * exactly as many products as the pages carry.
     *
     * @param  array<int,array{0: array<int,array<string,mixed>>, 1: bool, 2: ?string}>  $pages
     */
    protected function fakeCatalog(array $pages, string $shop = self::SHOP): void
    {
        $this->respondWith(function (Request $request) use ($pages) {
            $body = json_decode($request->body(), true) ?? [];

            if (str_contains((string) ($body['query'] ?? ''), 'productsCount')) {
                return Http::response(['data' => ['productsCount' => [
                    'count' => array_sum(array_map(static fn (array $page): int => count($page[0]), $pages)),
                ]]]);
            }

            $after = $body['variables']['after'] ?? null;
            $index = 0;

            foreach ($pages as $i => [, , $cursor]) {
                if ($i > 0 && ($pages[$i - 1][2] ?? null) === $after) {
                    $index = $i;
                }
            }

            [$nodes, $hasNext, $endCursor] = $pages[$index];

            return Http::response(['data' => ['products' => [
                'pageInfo' => ['hasNextPage' => $hasNext, 'endCursor' => $endCursor],
                'nodes' => $nodes,
            ]]]);
        });
    }

    /** Fake ONLY the soft-cap probe: the store reports $count products. */
    protected function fakeCatalogCount(int $count, string $shop = self::SHOP): void
    {
        $this->respondWith(fn (Request $request) => Http::response([
            'data' => ['productsCount' => ['count' => $count]],
        ]));
    }

    /** Fake the single-product fetch (the sync-one job + the products/update webhook). */
    protected function fakeSingleProduct(?array $node, string $shop = self::SHOP): void
    {
        $this->respondWith(fn (Request $request) => Http::response(['data' => ['product' => $node]]));
    }

    /** Fake the picker's search + count documents. */
    protected function fakeSearch(array $nodes, int $count = 0, string $shop = self::SHOP): void
    {
        $this->respondWith(function (Request $request) use ($nodes, $count) {
            $body = json_decode($request->body(), true) ?? [];
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'productsCount')) {
                return Http::response(['data' => ['productsCount' => ['count' => $count]]]);
            }

            return Http::response(['data' => ['products' => ['nodes' => $nodes]]]);
        });
    }
}
