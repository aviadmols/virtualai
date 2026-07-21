<?php

// === CONSTANTS: the merchant per-shop try-on prompt editor (TryOnPrompt page). ===
return [
    'nav' => 'Try-on prompt',
    'title' => 'Try-on prompt',
    'field' => [
        'label' => 'Your try-on prompt',
        'help' => 'Guide how the try-on image is generated so it stays faithful to your product. Weave in the product\'s own fields with the {{tokens}} below. Leave empty to use the platform default.',
        'product' => 'Preview a product (optional)',
        'product_help' => 'Pick a product to see and insert its own custom fields (metafields) as tokens.',
    ],
    'tokens' => [
        'title' => 'Product fields you can insert',
        'sub' => 'Click a chip to insert its token, or type "@" in the prompt to pick from the list. Each token is replaced with the product\'s real value when the image is generated.',
        'fixed' => 'Standard product fields',
        'metafields' => 'This product\'s custom fields',
        'none' => 'This product has no custom fields to insert.',
    ],
    'save' => 'Save prompt',
    'saved' => 'Prompt saved',
    'no_site' => 'Connect a shop to edit its try-on prompt.',
    'errors' => [
        'save_failed' => 'Could not save the prompt. Please try again.',
    ],
];
