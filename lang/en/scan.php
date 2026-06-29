<?php

// === KEYS: scan.* — PDP ingestion / scan-review. Mirror: lang/he/scan.php ===

return [
    'title' => 'Review scanned product',
    'paste_prompt' => 'Paste a product page URL',
    'scanning' => 'Reading your product page…',
    'confidence' => [
        'high' => 'High confidence',
        'medium' => 'Please confirm',
        'low' => 'Low confidence — please review',
        'none' => 'Not detected — add it manually',
    ],
    'field' => [
        'title' => 'Product title',
        'price' => 'Price',
        'description' => 'Description',
        'variants' => 'Variants',
        'dimensions' => 'Physical dimensions',
    ],
    'selector' => [
        'add_to_cart' => '"Add to cart" button',
        'product_image' => 'Product image',
        'title' => 'Title element',
        'price' => 'Price element',
        'variations' => 'Variations element',
        'detected' => 'Detected selector',
        'manual' => 'Enter selector manually',
        'pick' => 'Pick on page',
        'test' => 'Test selector',
        'test_ok' => 'Matches an element',
        'test_fail' => 'No match found',
    ],
    'action' => [
        'scan' => 'Scan',
        'rescan' => 'Re-scan',
        'confirm' => 'Confirm product',
    ],
    'blocked' => [
        'reason' => 'Review the flagged fields before confirming',
    ],
    'firstgen' => [
        'test' => 'Test on a live product page',
        'success' => 'Your widget is live',
        'error' => "The widget didn't load — see setup help",
    ],
    'errors' => [
        'unreachable' => "We couldn't reach that URL",
        'not_pdp' => "That doesn't look like a product page",
        'failed' => 'The scan failed. Try again.',
    ],
];
