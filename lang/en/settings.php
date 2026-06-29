<?php

// === KEYS: settings.* — merchant Settings group (M8 gallery + M9 privacy).
//          Mirror: lang/he/settings.php ===

return [
    'title' => 'Settings',

    'gallery' => [
        'title' => 'Gallery',
        'nav' => 'Gallery',
        'heading' => 'Try-on gallery',
        'sub' => 'The successful try-ons your shoppers generated on :site.',
        'caption' => [
            'no_product' => 'Untitled product',
        ],
        'purged' => 'Image removed by retention',
        'empty' => 'No try-ons yet',
        'empty_sub' => 'Once shoppers generate try-ons on this site, they appear here.',
        'error' => "Couldn't load the gallery. Try again.",
    ],

    'privacy' => [
        'title' => 'Privacy & retention',
        'nav' => 'Privacy & retention',
        'heading' => 'Privacy & retention',
        'sub' => 'Control how long shopper photos are kept and when signup is required on :site.',
        'saved' => 'Settings saved',
        'field' => [
            'retention_days' => 'Keep images for',
            'retention_days_help' => 'How long shopper and result images are kept before automatic deletion.',
            'free_generations' => 'Free try-ons before signup',
            'free_generations_help' => 'How many try-ons a shopper gets before they must sign up. Leave empty to never require signup.',
            'show_in_gallery' => 'Show try-ons in the on-site gallery',
            'show_in_gallery_help' => 'When on, successful try-ons appear in the storefront gallery.',
            'blur_source_photo' => 'Blur shopper photos in this admin',
            'blur_source_photo_help' => 'Protect shopper privacy by blurring uploaded photos in the merchant views.',
        ],
        'retention' => [
            '7' => '7 days',
            '30' => '30 days',
            '90' => '90 days',
            'until_delete' => 'Until I delete them',
        ],
        'free' => [
            'never' => 'Never require signup',
        ],
        'errors' => [
            'invalid_retention_days' => 'Pick one of the allowed retention windows.',
            'invalid_free_generations_before_signup' => 'Enter 0, a positive number, or leave empty.',
            'invalid_json_object' => 'This value is not in the expected format.',
            'save_failed' => "Couldn't save your settings. Try again.",
        ],
    ],

    'account' => [
        'title' => 'Account',
    ],
];
