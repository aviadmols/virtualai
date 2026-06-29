<?php

// === KEYS: leads.* — leads table + lead card. Mirror: lang/he/leads.php ===

return [
    'title' => 'Leads',
    'singular' => 'Lead',
    'export' => 'Export CSV',
    'anonymous' => 'Anonymous visitor',
    'col' => [
        'name' => 'Name',
        'email' => 'Email',
        'no_email' => 'No email',
        'phone' => 'Phone',
        'no_phone' => '—',
        'status' => 'Status',
        'tries' => 'Tries used',
        'last_attempt' => 'Last attempt',
        'never' => 'Never',
    ],
    'empty' => 'No leads yet',
    'empty_sub' => 'Leads appear here as shoppers try on your products.',
    'history' => [
        'empty' => 'No try-ons yet',
        'purged' => 'Image removed (retention)',
        'col' => [
            'product' => 'Product',
            'variant' => 'Variant',
            'result' => 'Result',
            'when' => 'When',
        ],
    ],
];
