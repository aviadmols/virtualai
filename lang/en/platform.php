<?php

// === KEYS: platform.* — Super-Admin control plane. Mirror: lang/he/platform.php ===

return [
    'title' => 'Platform control',

    // Nav-group labels for the platform sidebar (group order is in
    // PlatformPanelProvider::NAV_GROUPS). New keys added in Phase 8b — flagged to
    // product-ux-architect for the catalog.
    'nav' => [
        'overview' => 'Overview',
        'accounts' => 'Accounts',
        'sites' => 'Sites',
        'ai' => 'AI',
        'observability' => 'Observability',
        'controls' => 'Controls',
    ],

    'models' => [
        'title' => 'AI models',
        'col' => [
            'model_id' => 'Model ID',
            'operation' => 'Operation',
            'default' => 'Default',
            'fallback' => 'Fallback',
            'cost_hint' => 'Cost hint',
        ],
    ],
    'prompts' => [
        'title' => 'Prompts',
        'field' => [
            'scope' => 'Scope',
            'operation' => 'Operation',
            'product_type' => 'Product type',
            'system' => 'System prompt',
            'user' => 'User prompt',
            'version' => 'Version',
        ],
    ],
    'operations' => [
        'title' => 'AI operations',
        'field' => [
            'quality' => 'Image quality',
            'aspect' => 'Aspect ratio',
            'retention' => 'Retention',
            'multiplier' => 'Credit multiplier',
        ],
    ],
    'accounts' => [
        'title' => 'Accounts',
    ],
    'sites' => [
        'title' => 'Sites',
    ],
    'credits' => [
        'grant' => 'Grant credits',
        'adjust' => 'Adjust balance',
    ],
    'resolver' => [
        'preview' => 'Preview resolved',
        'winner' => 'Winning prompt',
        'trace' => 'Resolution order',
        'fellthrough' => 'Fell through to global',
    ],
];
