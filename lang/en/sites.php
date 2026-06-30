<?php

// === KEYS: sites.* — merchant sites resource. Mirror: lang/he/sites.php ===

return [
    'title' => 'Sites',
    'singular' => 'Site',
    'add' => 'Add site',
    'saved' => 'Site added',
    'updated' => 'Site saved',
    'scan' => [
        'label' => 'Scan a product',
        'heading' => 'Scan a product page',
        'sub' => 'Paste a product-page URL. We read its details and variants; you confirm before it goes live.',
        'url' => 'Product page URL',
        'url_placeholder' => 'https://shop.example.com/products/your-product',
        'submit' => 'Scan',
        'queued' => 'Scanning your product page',
        'queued_body' => 'This takes a few moments — the product appears below to review when it is ready.',
    ],
    'empty' => 'Add your first site to get started',
    'empty_sub' => 'A site is one storefront where the Tray On widget runs.',
    'field' => [
        'domain' => 'Domain',
        'domain_placeholder' => 'https://shop.example.com',
        'name' => 'Display name',
        'origins' => 'Allowed origins',
        'origins_placeholder' => 'https://shop.example.com',
        'origins_help' => 'The widget only runs on these origins.',
    ],
    'col' => [
        'name' => 'Name',
        'domain' => 'Domain',
        'no_domain' => 'No domain set',
        'status' => 'Status',
        'created' => 'Added',
    ],
    'status' => [
        'ready' => 'Ready',
        'pending' => 'Setup pending',
    ],
    'action' => [
        'edit' => 'Edit',
        'embed' => 'Install code',
        'products' => 'Products',
        'review' => 'Review',
    ],
    'settings' => [
        'title' => 'Site settings',
    ],
    'products' => [
        'title' => 'Products',
        'singular' => 'Product',
        'empty' => 'No products scanned yet',
        'empty_sub' => 'Scan a product page to add your first product.',
        'col' => [
            'name' => 'Product',
            'status' => 'Status',
            'confidence' => 'Confidence',
            'scanned' => 'Scanned',
        ],
        'status' => [
            'draft' => 'Needs review',
            'confirmed' => 'Live',
            'failed' => 'Scan failed',
        ],
    ],
    'errors' => [
        'duplicate' => 'A site with this domain already exists',
        'invalid_domain' => 'Enter a valid domain',
    ],
];
