<?php

namespace App\Domain\Scan\Represent;

use App\Domain\Scan\ScanConstants;

/**
 * CandidateHintBuilder — collects the top candidate nodes per selector role from
 * the fetched DOM, with their stable attributes, so the model's selector
 * suggestions are grounded in real nodes (not invented). Also used by the selector
 * detector as the starting candidate set.
 */
final class CandidateHintBuilder
{
    // === CONSTANTS ===
    // Per-role probe selectors, broad-to-narrow. Vendor-known patterns + semantic
    // + ARIA so a candidate is captured however the store marked it up.
    private const ROLE_PROBES = [
        ScanConstants::ROLE_TITLE => [
            'h1', '[itemprop="name"]', '.product-title', '.product__title', '[data-product-title]',
        ],
        ScanConstants::ROLE_PRICE => [
            '[itemprop="price"]', '.price', '.product-price', '.product__price',
            '[data-product-price]', '[data-price]',
        ],
        ScanConstants::ROLE_ADD_TO_CART => [
            '[name="add"]', 'button[type="submit"]', '.add-to-cart', '.product-form__cart-submit',
            '[data-add-to-cart]', 'button[aria-label*="cart" i]',
        ],
        ScanConstants::ROLE_PRODUCT_IMAGE => [
            '[itemprop="image"]', '.product__media img', '.product-image img',
            '[data-product-image]', '.product-single__photo img',
        ],
        ScanConstants::ROLE_DESCRIPTION => [
            '[itemprop="description"]', '.product-description', '.product__description',
            '[data-product-description]', '.rte',
        ],
        ScanConstants::ROLE_VARIATIONS => [
            'select[name*="option" i]', '.product-form__input', '.swatch', '[data-option-name]',
            'input[type="radio"][name*="option" i]', '.variant-input',
        ],
    ];

    private const MAX_CANDIDATES_PER_ROLE = 4;

    /**
     * Build {role => [candidate node descriptors]} for every selector role.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function build(ScanDom $dom): array
    {
        $hints = [];

        foreach (self::ROLE_PROBES as $role => $probes) {
            $collected = [];

            foreach ($probes as $probe) {
                foreach ($dom->candidates($probe, self::MAX_CANDIDATES_PER_ROLE) as $candidate) {
                    $candidate['matched_probe'] = $probe;
                    $candidate['matched_count'] = $dom->count($probe);
                    $collected[] = $candidate;

                    if (count($collected) >= self::MAX_CANDIDATES_PER_ROLE) {
                        break 2;
                    }
                }
            }

            if ($collected !== []) {
                $hints[$role] = $collected;
            }
        }

        return $hints;
    }
}
