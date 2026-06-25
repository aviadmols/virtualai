<?php

namespace App\Domain\Scan\Map;

use App\Domain\Scan\ScanConstants;

/**
 * ImageResolver — resolve the REAL image, not the lazy placeholder.
 *
 * The scar: a `data-src` lazy hero whose `src` is a 1x1 transparent GIF. The fix:
 * reject `data:`/1x1/spacer placeholders, prefer the largest srcset candidate then
 * data-src/data-lazy then src, and absolutise against the page origin. Dedupes a
 * gallery (same URL with different size params collapses to one).
 */
final class ImageResolver
{
    /** Resolve a single candidate URL to an absolute, real image (or null). */
    public function resolveUrl(?string $candidate, string $baseUrl): ?string
    {
        if ($candidate === null || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);

        if ($this->isPlaceholder($candidate)) {
            return null;
        }

        return $this->absolutise($candidate, $baseUrl);
    }

    /**
     * Pick the best URL from a set of candidate attributes for one <img>:
     * largest srcset > data-src/data-srcset/data-original/data-lazy > src.
     *
     * @param  array<string,string|null>  $attrs  {src, srcset, data-src, ...}
     */
    public function resolveBest(array $attrs, string $baseUrl): ?string
    {
        $srcset = $attrs['srcset'] ?? $attrs['data-srcset'] ?? null;

        if ($srcset !== null) {
            $largest = $this->largestFromSrcset($srcset);

            if ($largest !== null && ! $this->isPlaceholder($largest)) {
                return $this->absolutise($largest, $baseUrl);
            }
        }

        foreach (['data-src', 'data-original', 'data-lazy', 'data-image', 'src'] as $key) {
            $resolved = $this->resolveUrl($attrs[$key] ?? null, $baseUrl);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Dedupe + resolve a gallery list (absolute, placeholder-free, unique by path).
     *
     * @param  array<int,string>  $urls
     * @return array<int,string>
     */
    public function resolveGallery(array $urls, string $baseUrl): array
    {
        $out = [];
        $seen = [];

        foreach ($urls as $url) {
            $resolved = $this->resolveUrl($url, $baseUrl);

            if ($resolved === null) {
                continue;
            }

            // Collapse the same image with different size query params.
            $key = $this->dedupeKey($resolved);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $resolved;
        }

        return $out;
    }

    /** Reject obvious placeholders (1x1, data:, spacer, blank). */
    private function isPlaceholder(string $url): bool
    {
        $lower = strtolower($url);

        foreach (ScanConstants::PLACEHOLDER_IMAGE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** Largest srcset candidate by width descriptor (or last when none). */
    private function largestFromSrcset(string $srcset): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $entry);
            $url = $parts[0] ?? null;

            if ($url === null) {
                continue;
            }

            $width = 0;

            if (isset($parts[1]) && preg_match('/(\d+)w/', $parts[1], $m) === 1) {
                $width = (int) $m[1];
            }

            if ($width >= $bestWidth) {
                $bestWidth = $width;
                $best = $url;
            }
        }

        return $best;
    }

    /** Make a relative URL absolute against the page origin / base. */
    private function absolutise(string $url, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$url;
        }

        $parts = parse_url($baseUrl);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(substr($path, 0, strrpos($path, '/') ?: 0), '/');

        return $origin.$dir.'/'.$url;
    }

    /** Dedupe key: path without query/size params. */
    private function dedupeKey(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;

        // Strip common size suffixes (_400x, -large, @2x).
        $path = preg_replace('/[_-](\d+x\d*|small|medium|large|thumb)(?=\.[a-z]+$)/i', '', $path) ?? $path;

        return strtolower(($parts['host'] ?? '').$path);
    }
}
