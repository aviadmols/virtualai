<?php

namespace App\Domain\Generation;

use App\Models\EndUser;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * GenerationRequest — the validated input the (Phase-7) widget API hands to
 * StartGeneration. A plain, immutable bag: the resolved lead + product + selected
 * variant, the shopper photo bytes + mime, the height + optional body attributes,
 * the widget's stable client_request_id (collapses double-clicks), and the
 * use-my-photo consent flag.
 *
 * The HTTP layer (Phase 7) resolves the EndUser from site_key + anon_token and
 * validates the bytes/mime/height before constructing this; StartGeneration trusts
 * the shape but re-checks the invariants it owns (consent, variant ↔ product).
 */
final readonly class GenerationRequest
{
    /**
     * @param  array<string,mixed>  $extraAttrs  optional body/age/gender/angle hints
     */
    public function __construct(
        public EndUser $endUser,
        public Product $product,
        // Null for a single-SKU product (no variants) — the try-on uses the product's main image.
        public ?ProductVariant $variant,
        public string $photoBytes,
        public string $photoMime,
        public int $userHeight,
        public string $clientRequestId,
        public bool $photoConsent,
        public array $extraAttrs = [],
    ) {}
}
