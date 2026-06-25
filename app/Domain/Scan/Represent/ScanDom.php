<?php

namespace App\Domain\Scan\Represent;

use Symfony\Component\DomCrawler\Crawler;

/**
 * ScanDom — a thin, safe wrapper over Symfony DomCrawler for the scan layer.
 *
 * Loads the fetched HTML once and exposes the two operations the scan needs:
 * counting how many elements a CSS selector resolves to (selector verification)
 * and reading candidate nodes' stable attributes (candidate hints). Swallows
 * malformed-selector errors into a 0 count so a bad merchant selector can never
 * throw — it just reports "matches 0 elements".
 */
final class ScanDom
{
    private readonly Crawler $crawler;

    public function __construct(string $html, private readonly string $baseUrl = '')
    {
        // Wrap so a fragment still parses; DomCrawler tolerates full documents too.
        $this->crawler = new Crawler($html, $this->baseUrl !== '' ? $this->baseUrl : null);
    }

    public static function fromHtml(string $html, string $baseUrl = ''): self
    {
        return new self($html, $baseUrl);
    }

    /**
     * How many elements a CSS selector resolves to in this DOM. Returns 0 on a
     * malformed selector (never throws) so verification can flag it as no-match.
     */
    public function count(string $cssSelector): int
    {
        if (trim($cssSelector) === '') {
            return 0;
        }

        try {
            return $this->crawler->filter($cssSelector)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** True when a selector resolves to EXACTLY one element. */
    public function matchesExactlyOne(string $cssSelector): bool
    {
        return $this->count($cssSelector) === 1;
    }

    /**
     * Read the first N candidate nodes for a CSS selector as stable-attribute
     * descriptors {tag, id, classes, data, aria, itemprop, text}. Used to build
     * the candidate-selector hints the model grounds its suggestions on.
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates(string $cssSelector, int $limit = 5): array
    {
        $out = [];

        try {
            $nodes = $this->crawler->filter($cssSelector);
        } catch (\Throwable) {
            return [];
        }

        $nodes->each(function (Crawler $node) use (&$out, $limit): void {
            if (count($out) >= $limit) {
                return;
            }

            $element = $node->getNode(0);

            if ($element === null) {
                return;
            }

            $out[] = $this->describe($node, $element);
        });

        return $out;
    }

    /** First matching node's text, trimmed; null when none. */
    public function text(string $cssSelector): ?string
    {
        try {
            $nodes = $this->crawler->filter($cssSelector);
        } catch (\Throwable) {
            return null;
        }

        if ($nodes->count() === 0) {
            return null;
        }

        $text = trim($nodes->first()->text(''));

        return $text === '' ? null : $text;
    }

    /** First matching node's attribute value; null when none. */
    public function attr(string $cssSelector, string $attribute): ?string
    {
        try {
            $nodes = $this->crawler->filter($cssSelector);
        } catch (\Throwable) {
            return null;
        }

        if ($nodes->count() === 0) {
            return null;
        }

        return $nodes->first()->attr($attribute);
    }

    /** Stable-attribute descriptor for a single node. */
    private function describe(Crawler $node, \DOMNode $element): array
    {
        $data = [];
        $aria = [];

        if ($element instanceof \DOMElement) {
            foreach ($element->attributes as $attribute) {
                $name = $attribute->name;

                if (str_starts_with($name, 'data-')) {
                    $data[$name] = $attribute->value;
                } elseif (str_starts_with($name, 'aria-')) {
                    $aria[$name] = $attribute->value;
                }
            }
        }

        $classAttr = $node->attr('class') ?? '';

        return [
            'tag' => strtolower($element->nodeName),
            'id' => $node->attr('id'),
            'classes' => array_values(array_filter(preg_split('/\s+/', trim($classAttr)) ?: [])),
            'data' => $data,
            'aria' => $aria,
            'itemprop' => $node->attr('itemprop'),
            'role' => $node->attr('role'),
            'text' => mb_substr(trim($node->text('')), 0, 80),
        ];
    }
}
