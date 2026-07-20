<?php

namespace App\Domain\Sites;

use App\Models\Product;

/**
 * ButtonVisibility — the merchant's rule for WHERE the "Try it on" button appears.
 *
 * MODE_ALL: every confirmed product page. MODE_TAG / MODE_TYPE / MODE_COLLECTION: only a
 * product carrying one of the listed tags / of one of the listed product types / in one of
 * the listed collections. Evaluated SERVER-SIDE in the widget bootstrap — a product that
 * fails the rule resolves to null, so the widget simply never mounts the button there.
 *
 * FAIL-OPEN: an absent rule, MODE_ALL, or a configured mode with no values all show the
 * button everywhere. The safe default is "the button works", never "the whole store lost its
 * button because a rule was half-filled". Matching is case-insensitive; a collection is
 * matched by either its handle or its title (a merchant thinks in titles, the sync stores both).
 */
final class ButtonVisibility
{
    // === CONSTANTS ===
    public const KEY_MODE = 'mode';

    public const KEY_VALUES = 'values';

    public const MODE_ALL = 'all';

    public const MODE_TAG = 'tag';

    public const MODE_TYPE = 'product_type';

    public const MODE_COLLECTION = 'collection';

    public const MODES = [self::MODE_ALL, self::MODE_TAG, self::MODE_TYPE, self::MODE_COLLECTION];

    // The scan_raw.shopify.* keys the tag/collection lists live under (written by ShopifyProductMapper).
    private const RAW_ROOT = 'shopify';

    private const RAW_TAGS = 'tags';

    private const RAW_COLLECTIONS = 'collections';

    // A rule never lists more than this many values (a UI/DoS guard).
    private const MAX_VALUES = 50;

    private const VALUE_MAX_LEN = 255;

    /**
     * @param  array<int,string>  $values
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $values,
    ) {}

    /**
     * Resolve the stored rule blob into a valid object (fail-open on anything malformed).
     *
     * @param  array<string,mixed>|null  $raw
     */
    public static function resolve(?array $raw): self
    {
        $raw = is_array($raw) ? $raw : [];
        $mode = (string) ($raw[self::KEY_MODE] ?? self::MODE_ALL);

        if (! in_array($mode, self::MODES, true)) {
            $mode = self::MODE_ALL;
        }

        return new self($mode, self::cleanValues($raw[self::KEY_VALUES] ?? []));
    }

    /**
     * Normalize merchant input to the stored blob shape (used by the merchant settings save).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array
    {
        $rule = self::resolve($input);

        return [
            self::KEY_MODE => $rule->mode,
            self::KEY_VALUES => $rule->values,
        ];
    }

    /**
     * Does this product satisfy the rule? FAIL-OPEN on MODE_ALL / no values.
     */
    public function matches(Product $product): bool
    {
        if ($this->mode === self::MODE_ALL || $this->values === []) {
            return true;
        }

        $wanted = array_map(self::fold(...), $this->values);
        foreach ($this->haystack($product) as $candidate) {
            if (in_array(self::fold($candidate), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The product's own values for the active mode: its type, its Shopify tags, or its
     * collections (handle AND title). Reads scan_raw for the Shopify-sourced lists.
     *
     * @return array<int,string>
     */
    private function haystack(Product $product): array
    {
        return match ($this->mode) {
            self::MODE_TYPE => array_filter([(string) $product->product_type], static fn (string $v): bool => $v !== ''),
            self::MODE_TAG => self::rawStrings($product, self::RAW_TAGS),
            self::MODE_COLLECTION => self::rawCollections($product),
            default => [],
        };
    }

    /** A flat string list from scan_raw.shopify.{key} (tags are plain strings). @return array<int,string> */
    private static function rawStrings(Product $product, string $key): array
    {
        $raw = is_array($product->scan_raw) ? $product->scan_raw : [];
        $list = $raw[self::RAW_ROOT][$key] ?? [];

        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $list,
        ), static fn (string $v): bool => $v !== ''));
    }

    /** Collection handles AND titles (each collection is {handle,title}). @return array<int,string> */
    private static function rawCollections(Product $product): array
    {
        $raw = is_array($product->scan_raw) ? $product->scan_raw : [];
        $list = $raw[self::RAW_ROOT][self::RAW_COLLECTIONS] ?? [];

        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $c) {
            if (is_string($c) && $c !== '') {
                $out[] = $c;
            } elseif (is_array($c)) {
                foreach (['handle', 'title'] as $k) {
                    if (isset($c[$k]) && is_string($c[$k]) && $c[$k] !== '') {
                        $out[] = $c[$k];
                    }
                }
            }
        }

        return $out;
    }

    /** @param  mixed  $values @return array<int,string> */
    private static function cleanValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $v) {
            if (! is_scalar($v)) {
                continue;
            }
            $s = mb_substr(trim((string) $v), 0, self::VALUE_MAX_LEN);
            if ($s !== '') {
                $clean[self::fold($s)] = $s; // de-dupe case-insensitively, keep first casing
            }
            if (count($clean) >= self::MAX_VALUES) {
                break;
            }
        }

        return array_values($clean);
    }

    private static function fold(string $v): string
    {
        return mb_strtolower(trim($v));
    }
}
