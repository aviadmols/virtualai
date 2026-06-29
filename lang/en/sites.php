<?php

// === KEYS: sites.* — merchant sites resource. Mirror: lang/he/sites.php ===

return [
    'title' => 'Sites',
    'singular' => 'Site',
    'add' => 'Add site',
    'saved' => 'Site added',
    'updated' => 'Site saved',
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
