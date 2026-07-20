<?php

namespace App\Domain\Ai;

use App\Models\Product;
use App\Models\Site;

/**
 * MentionResolver — turns the @entity tags in a prompt into reference IMAGES for the AI call,
 * tenant + site scoped and fail-closed.
 *
 * A @product_{id} token attaches that product's main image as a reference input — the same
 * mechanism the storyboard reference flow uses. Resolution is bounded by the fail-closed
 * BelongsToAccount global scope PLUS an explicit site filter: a foreign / unknown / imageless
 * id contributes NOTHING (never another tenant's image, never a broken url). Capped at
 * MentionTags::MAX_REFERENCES.
 *
 * Fail-closed by the tenant scope: called with no bound tenant, the global scope constrains to
 * an impossible account and the resolver returns [] — so a forgotten Tenant::run() leaks
 * nothing. The metafield tokens (@materials, …) are NOT resolved here; they flow through the
 * existing ProductFacts strtr substitution at generation time.
 *
 * @media_{id} / @file_{id} (the Shopify media library) will resolve here once the Shopify Files
 * read exists; until then they simply contribute nothing (fail-closed), never an error.
 */
final class MentionResolver
{
    /**
     * The reference images a prompt's @entity tags attach — tenant + site scoped, capped,
     * fail-closed.
     *
     * @return array<int,ImagePayload>
     */
    public function referenceImages(string $prompt, Site $site): array
    {
        $ids = self::entityIds(self::tokens($prompt), MentionTags::PREFIX_PRODUCT);

        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->where('site_id', $site->getKey())
            ->whereIn('id', $ids)
            ->whereNotNull('main_image_url')
            ->limit(MentionTags::MAX_REFERENCES)
            ->get(['id', 'account_id', 'site_id', 'main_image_url']);

        $images = [];

        foreach ($products as $product) {
            $url = (string) $product->main_image_url;

            // Only a real http(s) url is a valid reference; anything else is skipped so a bad
            // stored value can never 404 the provider and fail the whole generation.
            if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
                $images[] = ImagePayload::fromUrl($url);
            }
        }

        return $images;
    }

    /** The product meta-field placeholder tokens a composer may offer for autocomplete. */
    public function metafieldTokens(): array
    {
        return MentionTags::PRODUCT_METAFIELD_TOKENS;
    }

    /**
     * Extract the unique @tokens from prompt text (unicode-aware; Hebrew tags included).
     *
     * @return array<int,string>
     */
    public static function tokens(string $prompt): array
    {
        if (preg_match_all(MentionTags::TOKEN_PATTERN, $prompt, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * The numeric entity ids for a prefix. Only a NUMERIC suffix is an entity id, so a
     * metafield token that shares the prefix (@product_details, @product_type) is never read
     * as an id.
     *
     * @param  array<int,string>  $tokens
     * @return array<int,int>
     */
    private static function entityIds(array $tokens, string $prefix): array
    {
        $ids = [];

        foreach ($tokens as $token) {
            if (! str_starts_with($token, $prefix)) {
                continue;
            }

            $suffix = substr($token, strlen($prefix));

            if ($suffix !== '' && ctype_digit($suffix)) {
                $ids[] = (int) $suffix;
            }
        }

        return array_values(array_unique($ids));
    }
}
