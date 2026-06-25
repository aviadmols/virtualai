<?php

namespace App\Domain\Scan\Represent;

use App\Domain\Scan\ScanConstants;
use Symfony\Component\DomCrawler\Crawler;

/**
 * StructuredData — lift the HIGHEST-confidence signals from a PDP before the model
 * sees the page: schema.org/Product JSON-LD, Open Graph tags, and microdata.
 *
 * This is gold: a JSON-LD Product node carries name/price/priceCurrency/image/sku
 * and per-offer variants with near-1.0 confidence. We pre-extract it and hand it
 * to the model as a structured block alongside the trimmed DOM so the model
 * reconciles + fills gaps rather than guessing from raw markup.
 */
final class StructuredData
{
    /**
     * @return array{jsonld: array<int,array<string,mixed>>, og: array<string,string>, microdata: array<string,string>}
     */
    public static function lift(string $html): array
    {
        $crawler = new Crawler($html);

        return [
            'jsonld' => self::jsonLd($crawler),
            'og' => self::openGraph($crawler),
            'microdata' => self::microdata($crawler),
        ];
    }

    /**
     * All application/ld+json blocks, decoded. Flattens a top-level @graph so a
     * Product node inside one is reachable.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function jsonLd(Crawler $crawler): array
    {
        $blocks = [];

        try {
            $scripts = $crawler->filter('script[type="'.ScanConstants::JSONLD_TYPE.'"]');
        } catch (\Throwable) {
            return [];
        }

        $scripts->each(function (Crawler $node) use (&$blocks): void {
            $decoded = json_decode(trim($node->text('')), true);

            if (! is_array($decoded)) {
                return;
            }

            // A list of nodes, a @graph wrapper, or a single node — normalise to a list.
            if (array_is_list($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_array($entry)) {
                        $blocks[] = $entry;
                    }
                }
            } elseif (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $entry) {
                    if (is_array($entry)) {
                        $blocks[] = $entry;
                    }
                }
            } else {
                $blocks[] = $decoded;
            }
        });

        return $blocks;
    }

    /**
     * The Product JSON-LD node, if any (the first @type Product across all blocks).
     *
     * @param  array<int,array<string,mixed>>  $blocks
     * @return array<string,mixed>|null
     */
    public static function productNode(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            $type = $block['@type'] ?? null;
            $types = is_array($type) ? $type : [$type];

            foreach ($types as $t) {
                if (is_string($t) && strtolower($t) === 'product') {
                    return $block;
                }
            }
        }

        return null;
    }

    /**
     * Open Graph + product meta tags.
     *
     * @return array<string,string>
     */
    private static function openGraph(Crawler $crawler): array
    {
        $og = [];

        try {
            $metas = $crawler->filter('meta[property], meta[name]');
        } catch (\Throwable) {
            return [];
        }

        $metas->each(function (Crawler $node) use (&$og): void {
            $key = $node->attr('property') ?? $node->attr('name');
            $content = $node->attr('content');

            if ($key === null || $content === null) {
                return;
            }

            if (str_starts_with($key, 'og:') || str_starts_with($key, 'product:') || str_starts_with($key, 'twitter:')) {
                $og[$key] = $content;
            }
        });

        return $og;
    }

    /**
     * itemprop microdata values (name/price/priceCurrency/image/description/...).
     *
     * @return array<string,string>
     */
    private static function microdata(Crawler $crawler): array
    {
        $data = [];

        try {
            $nodes = $crawler->filter('[itemprop]');
        } catch (\Throwable) {
            return [];
        }

        $nodes->each(function (Crawler $node) use (&$data): void {
            $prop = $node->attr('itemprop');

            if ($prop === null || isset($data[$prop])) {
                return;
            }

            $value = $node->attr('content')
                ?? $node->attr('src')
                ?? $node->attr('href')
                ?? trim($node->text(''));

            if ($value !== null && $value !== '') {
                $data[$prop] = $value;
            }
        });

        return $data;
    }
}
