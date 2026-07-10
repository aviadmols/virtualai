<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

/**
 * FalModelCatalog — the browsable fal.ai model registry for the admin model pickers.
 *
 * fal's public catalog (https://fal.ai/api/models, no auth) lists the FULL model registry with
 * category filtering (?categories=text-to-image) and pagination (40/page). This service pulls a
 * category's pages, caches the result (the catalog changes rarely; a broken fetch must never
 * break an admin screen), and returns Select-ready `id => "id — title"` options. Read-only and
 * spend-free — model EXECUTION goes through the Fal clients, never through here.
 */
final class FalModelCatalog
{
    // === CONSTANTS ===
    // fal's task categories, as the catalog spells them.
    public const CAT_TEXT_TO_IMAGE = 'text-to-image';
    public const CAT_IMAGE_TO_IMAGE = 'image-to-image';
    public const CAT_TEXT_TO_VIDEO = 'text-to-video';
    public const CAT_IMAGE_TO_VIDEO = 'image-to-video';

    // The category sets the two admin pickers browse.
    public const IMAGE_CATEGORIES = [self::CAT_TEXT_TO_IMAGE, self::CAT_IMAGE_TO_IMAGE];
    public const VIDEO_CATEGORIES = [self::CAT_IMAGE_TO_VIDEO, self::CAT_TEXT_TO_VIDEO];

    private const CFG_CATALOG_URL = 'services.fal.catalog_url';
    private const CFG_TIMEOUT = 'services.fal.timeout';

    private const MODELS_PATH = '/models';
    private const CACHE_PREFIX = 'fal.catalog.';
    private const CACHE_TTL_SECONDS = 3600;
    // An empty result (outage) is cached only briefly so a transient failure can't poison the
    // picker for the full TTL.
    private const EMPTY_TTL_SECONDS = 120;
    private const CATEGORY_CACHE_PREFIX = 'fal.model-category.';

    // 40 models/page upstream; 5 pages ≈ the 200 most relevant per category keeps the
    // dropdown usable. The full registry stays reachable by typing any id manually.
    private const MAX_PAGES_PER_CATEGORY = 5;

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Select-ready options for a set of categories: `id => "id — title"`, cached per category.
     * Returns [] when the catalog is unreachable (the picker then falls back to suggestions).
     *
     * @param  array<int,string>  $categories
     * @return array<string,string>
     */
    public function options(array $categories): array
    {
        $options = [];

        foreach ($categories as $category) {
            foreach ($this->category($category) as $id => $title) {
                $options[$id] = $title !== '' ? $id.' — '.$title : $id;
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * The catalog category of ONE model id ('text-to-image', 'image-to-video', …), or null when
     * the model is unknown or the catalog is unreachable. Lets a client gate capabilities (e.g.
     * never send input images to a text-to-image model). Cached per id; never throws.
     */
    public function categoryOf(string $modelId): ?string
    {
        $item = $this->find($modelId);

        return is_string($item['category'] ?? null) ? $item['category'] : null;
    }

    /**
     * fal's ADVISORY per-run price for a model, parsed from the catalog's human-readable pricing
     * text (the only price surface fal exposes) — in micro-USD, or null when unknown. Used only
     * as the initial cost hint when a fal model is auto-catalogued; the admin owns the final
     * price on the Models page.
     */
    public function priceHintMicroUsd(string $modelId): ?int
    {
        $pricing = $this->find($modelId)['pricingInfoOverride'] ?? null;

        if (! is_string($pricing) || preg_match('/\$\s*([0-9]+(?:\.[0-9]+)?)/', $pricing, $match) !== 1) {
            return null;
        }

        $micro = (int) round(((float) $match[1]) * 1_000_000);

        return $micro > 0 ? $micro : null;
    }

    /** The raw catalog item for ONE model id (cached), or null when unknown/unreachable. @return array<string,mixed>|null */
    public function find(string $modelId): ?array
    {
        if ($modelId === '') {
            return null;
        }

        $cacheKey = self::CATEGORY_CACHE_PREFIX.md5($modelId);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached === [] ? null : $cached; // [] marks a cached miss
        }

        $item = $this->lookupItem($modelId);
        Cache::put($cacheKey, $item ?? [], $item === null ? self::EMPTY_TTL_SECONDS : self::CACHE_TTL_SECONDS);

        return $item;
    }

    /** @return array<string,mixed>|null */
    private function lookupItem(string $modelId): ?array
    {
        try {
            $response = $this->http
                ->baseUrl((string) config(self::CFG_CATALOG_URL))
                ->timeout((int) config(self::CFG_TIMEOUT))
                ->acceptJson()
                ->get(self::MODELS_PATH, ['keywords' => $modelId]);
        } catch (ConnectionException) {
            return null;
        }

        $items = is_array($response->json('items')) ? $response->json('items') : [];

        foreach ($items as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $modelId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * One category's models as `id => title`, cached. A fetch failure yields [] with a SHORT
     * cache TTL (a transient outage can't poison the picker for an hour) — never throws into an
     * admin screen.
     *
     * @return array<string,string>
     */
    private function category(string $category): array
    {
        $cacheKey = self::CACHE_PREFIX.$category;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $models = $this->fetchCategory($category);
        Cache::put($cacheKey, $models, $models === [] ? self::EMPTY_TTL_SECONDS : self::CACHE_TTL_SECONDS);

        return $models;
    }

    /** @return array<string,string> */
    private function fetchCategory(string $category): array
    {
        $models = [];

        for ($page = 1; $page <= self::MAX_PAGES_PER_CATEGORY; $page++) {
            $items = $this->fetchPage($category, $page);

            if ($items === null) {
                break; // unreachable/invalid — return what we have (possibly [])
            }

            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (is_string($id) && $id !== '' && ($item['deprecated'] ?? false) !== true) {
                    $models[$id] = is_string($item['title'] ?? null) ? $item['title'] : '';
                }
            }

            if (count($items) === 0) {
                break; // past the last page
            }
        }

        return $models;
    }

    /** One catalog page's items, or null when the catalog is unreachable. @return array<int,mixed>|null */
    private function fetchPage(string $category, int $page): ?array
    {
        try {
            $response = $this->http
                ->baseUrl((string) config(self::CFG_CATALOG_URL))
                ->timeout((int) config(self::CFG_TIMEOUT))
                ->acceptJson()
                ->get(self::MODELS_PATH, ['categories' => $category, 'page' => $page]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $items = $response->json('items');

        return is_array($items) ? $items : null;
    }
}
