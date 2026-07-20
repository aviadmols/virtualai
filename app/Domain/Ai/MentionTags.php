<?php

namespace App\Domain\Ai;

use App\Domain\Generation\ProductFacts;

/**
 * MentionTags — the ONE authority for the @-mention tag scheme shared by every composer
 * (the try-on prompt editor, Image Studio, Banners) and the MentionResolver.
 *
 * Two kinds of @token:
 *  - a METAFIELD token (@materials) is a per-product PLACEHOLDER: it maps to a strtr var
 *    filled at generation time from ProductFacts. It attaches no image.
 *  - an ENTITY token (@product_42) references a specific tenant-owned asset whose IMAGE is
 *    attached to the AI call as a reference input.
 *
 * Entity tokens use UNDERSCORES (product_42, not product-42): the shared token regex is
 * /@([\p{L}\p{N}_]+)/u — a hyphen truncates the token. Entity ids are NUMERIC, which is what
 * separates @product_42 (an entity) from @product_details (a metafield that also starts
 * "product_"). Do NOT broaden the regex; it would change every existing composer's
 * serialization (StoryboardFrameGenerator + _composer-script.blade.php).
 */
final class MentionTags
{
    // === CONSTANTS ===
    // The shared token regex — MUST stay identical to StoryboardFrameGenerator::referenceImages
    // and _composer-script.blade.php. Unicode-aware so Hebrew tags match.
    public const TOKEN_PATTERN = '/@([\p{L}\p{N}_]+)/u';

    // Entity token prefixes (underscore scheme). The suffix is a numeric id.
    public const PREFIX_PRODUCT = 'product_';

    public const PREFIX_MEDIA = 'media_';

    public const PREFIX_FILE = 'file_';

    // Reference-image cap, shared with the storyboard reference flow (MAX_REFERENCES).
    public const MAX_REFERENCES = 4;

    // The product meta-field placeholders a prompt may @-mention. product_name / product_type
    // are the product's own identity; the rest mirror ProductFacts (the vars the try-on
    // substitution actually fills), so the composer never offers a token that renders blank.
    public const METAFIELD_PRODUCT_NAME = 'product_name';

    public const METAFIELD_PRODUCT_TYPE = 'product_type';

    public const PRODUCT_METAFIELD_TOKENS = [
        self::METAFIELD_PRODUCT_NAME,
        self::METAFIELD_PRODUCT_TYPE,
        ProductFacts::VAR_DESCRIPTION,
        ProductFacts::VAR_MATERIALS,
        ProductFacts::VAR_OPTIONS,
        ProductFacts::VAR_DIMENSIONS,
        ProductFacts::VAR_PRODUCT_DETAILS,
    ];
}
