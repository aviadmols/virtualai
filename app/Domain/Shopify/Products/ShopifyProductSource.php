<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Products\ProductSource;
use App\Domain\Scan\Map\MappedProduct;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Domain\Shopify\Api\ShopifyGraphQLClient;
use App\Models\ShopifyConnection;
use App\Models\Site;
use RuntimeException;

/**
 * ShopifyProductSource — the Shopify rail's implementation of ProductSource: read the
 * Admin API, hand back the SAME MappedProduct bag the PDP scanner produces.
 *
 * It is a pure READER. It never persists, never decides a status, never charges — the
 * catalog walk / single-product sync / webhook handler own that, through the shared
 * PersistProduct writer. Throttling is handled inside ShopifyGraphQLClient (Retry-After
 * honoured, typed CODE_THROTTLED once the budget is spent), so a caller simply catches
 * ShopifyApiException and parks its cursor.
 *
 * Tenant-safety: the connection is resolved through the site's BelongsToAccount relation
 * (the tenant is already bound by the calling job) — never by an unscoped shop lookup.
 */
final readonly class ShopifyProductSource implements ProductSource
{
    // === CONSTANTS ===
    // Products per catalog page. Kept modest so one page's query cost stays well inside
    // Shopify's leaky bucket even on a store with 100-variant products.
    private const CFG_PAGE_SIZE = 'shopify.sync.page_size';

    private const DEFAULT_PAGE_SIZE = 25;

    // How many results the picker's live search returns.
    private const CFG_SEARCH_LIMIT = 'shopify.sync.search_limit';

    private const DEFAULT_SEARCH_LIMIT = 20;

    // The catalog filter: only products the storefront can actually sell.
    private const CFG_CATALOG_QUERY = 'shopify.sync.catalog_query';

    private const RESPONSE_PRODUCTS = 'products';

    private const RESPONSE_PRODUCT = 'product';

    private const RESPONSE_NODES = 'nodes';

    private const RESPONSE_PAGE_INFO = 'pageInfo';

    private const RESPONSE_HAS_NEXT = 'hasNextPage';

    private const RESPONSE_END_CURSOR = 'endCursor';

    private const RESPONSE_COUNT = 'productsCount';

    private const MSG_NO_CONNECTION = 'Site #%s has no installed Shopify connection.';

    private const MSG_NOT_FOUND = 'Shopify product %s was not found on %s.';

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyProductMapper $mapper,
    ) {}

    /**
     * Fetch + map ONE product by GID.
     *
     * @return array{0: MappedProduct, 1: ShopifyProductRef}
     *
     * @throws ShopifyApiException|ShopifyProductNotFoundException
     */
    public function fetch(Site $site, string $reference): array
    {
        $connection = $this->connection($site);
        $shop = (string) $connection->shop_domain;

        $data = $this->client->query($connection, ShopifyProductQueries::singleProduct(), [
            'id' => ShopifyGid::for(ShopifyGid::TYPE_PRODUCT, $reference),
        ]);

        $node = $data[self::RESPONSE_PRODUCT] ?? null;

        if (! is_array($node) || $node === []) {
            throw new ShopifyProductNotFoundException(sprintf(self::MSG_NOT_FOUND, $reference, $shop));
        }

        return [$this->mapper->map($node, $shop), $this->mapper->origin($node, $shop)];
    }

    /**
     * One cursor page of the catalog. $after = null starts the walk; the returned
     * endCursor is the resume point the sync run persists.
     *
     * @throws ShopifyApiException
     */
    public function page(Site $site, ?string $after = null): ShopifyProductPage
    {
        $connection = $this->connection($site);
        $shop = (string) $connection->shop_domain;

        $data = $this->client->query($connection, ShopifyProductQueries::catalogPage(), [
            'first' => $this->pageSize(),
            'after' => $after,
            'query' => $this->catalogQuery(),
        ]);

        $products = (array) ($data[self::RESPONSE_PRODUCTS] ?? []);
        $pageInfo = (array) ($products[self::RESPONSE_PAGE_INFO] ?? []);

        $entries = [];

        foreach ((array) ($products[self::RESPONSE_NODES] ?? []) as $node) {
            $node = (array) $node;
            $entries[] = [$this->mapper->map($node, $shop), $this->mapper->origin($node, $shop)];
        }

        return new ShopifyProductPage(
            entries: $entries,
            hasNextPage: (bool) ($pageInfo[self::RESPONSE_HAS_NEXT] ?? false),
            endCursor: isset($pageInfo[self::RESPONSE_END_CURSOR]) ? (string) $pageInfo[self::RESPONSE_END_CURSOR] : null,
        );
    }

    /**
     * The picker's live search. The term rides as a typed GraphQL VARIABLE (never
     * interpolated into the document), so a merchant's input can never become query
     * syntax.
     *
     * @return array<int,ShopifyProductSummary>
     *
     * @throws ShopifyApiException
     */
    public function search(Site $site, string $term): array
    {
        $connection = $this->connection($site);

        $data = $this->client->query($connection, ShopifyProductQueries::search(), [
            'first' => (int) (config(self::CFG_SEARCH_LIMIT) ?? self::DEFAULT_SEARCH_LIMIT),
            'query' => $this->searchQuery($term),
        ]);

        $nodes = (array) ($data[self::RESPONSE_PRODUCTS][self::RESPONSE_NODES] ?? []);

        return array_map(
            static fn (mixed $node): ShopifyProductSummary => ShopifyProductSummary::fromNode((array) $node),
            array_values($nodes),
        );
    }

    /** How many products an "import all" would walk (the soft-cap check reads this). */
    public function count(Site $site): int
    {
        $data = $this->client->query($this->connection($site), ShopifyProductQueries::count(), [
            'query' => $this->catalogQuery(),
        ]);

        return (int) ($data[self::RESPONSE_COUNT]['count'] ?? 0);
    }

    /**
     * The site's INSTALLED Shopify connection, read through the tenant-scoped relation.
     * A disconnected/never-connected site is a loud RuntimeException — a sync must never
     * silently no-op (the merchant would think their catalog imported).
     */
    private function connection(Site $site): ShopifyConnection
    {
        $connection = $site->shopifyConnection;

        if (! $connection instanceof ShopifyConnection || ! $connection->isInstalled()) {
            throw new RuntimeException(sprintf(self::MSG_NO_CONNECTION, $site->getKey()));
        }

        return $connection;
    }

    private function pageSize(): int
    {
        return (int) (config(self::CFG_PAGE_SIZE) ?? self::DEFAULT_PAGE_SIZE);
    }

    /** The catalog filter (config-driven; null = every product). */
    private function catalogQuery(): ?string
    {
        $query = config(self::CFG_CATALOG_QUERY);

        return is_string($query) && $query !== '' ? $query : null;
    }

    /** The merchant's term, combined with the catalog filter. */
    private function searchQuery(string $term): ?string
    {
        $term = trim($term);
        $catalog = $this->catalogQuery();

        if ($term === '') {
            return $catalog;
        }

        // Shopify's search syntax: a bare term matches title/sku/vendor. Quote it so a
        // term containing a colon/space cannot be read as a field filter.
        $quoted = '"'.str_replace('"', '', $term).'"';

        return $catalog === null ? $quoted : $catalog.' AND '.$quoted;
    }
}
