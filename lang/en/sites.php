<?php

// === KEYS: sites.* — merchant sites resource. Mirror: lang/he/sites.php ===

return [
    'title' => 'Sites',
    'singular' => 'Site',
    'add' => 'Add site',
    'saved' => 'Site added',
    'updated' => 'Site saved',
    'register' => [
        'label' => 'Add a shop',
        'not_owner' => 'Your user isn’t linked to a merchant account, so it can’t add a shop.',
    ],
    'profile' => [
        'label' => 'Shop settings',
    ],
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
    'empty_sub' => 'A site is one storefront where the Vsio widget runs.',
    'field' => [
        'domain' => 'Domain',
        'domain_placeholder' => 'https://shop.example.com',
        'name' => 'Display name',
        'category' => 'Store type',
        'category_help' => 'Picks the tailored try-on prompt for your products (jewelry, clothing, furniture, …).',
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
    // WS1 — the per-shop Overview hub (KPI band, quick-link cards, recent activity).
    'hub' => [
        'kpi' => [
            'products' => 'Confirmed products',
            'generations' => 'Try-ons (30d)',
            'leads' => 'Registered users',
            'balance' => 'Spendable credit',
        ],
        'tools' => [
            'title' => 'Manage this shop',
            'sub' => 'Everything for this shop in one place.',
            'placement' => [
                'title' => 'Button placement',
                'sub' => 'Pick where the Vsio button sits on your product page.',
            ],
            'history' => [
                'title' => 'Try-on history',
                'sub' => 'Every try-on your shoppers generated on this shop.',
            ],
            'users' => [
                'title' => 'Registered users',
                'sub' => 'Your leads and what each one did on your shop.',
            ],
            'gallery' => [
                'title' => 'Gallery',
                'sub' => 'The on-site wall of successful try-ons.',
            ],
            'privacy' => [
                'title' => 'Privacy & retention',
                'sub' => 'How long uploaded photos are kept, and shopper privacy.',
            ],
        ],
        'activity' => [
            'title' => 'Recent activity',
            'subtitle' => 'The latest things that happened on this shop.',
            'empty' => 'No activity yet',
        ],
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
