<?php

// === KEYS: platform.* — Super-Admin control plane. Mirror: lang/he/platform.php ===
// Every string the platform panel renders. Statuses live in status.php; shared
// empty/loading/error in states.php; shared action verbs in actions.php.

return [
    'title' => 'Platform control',

    // Nav-group labels for the platform sidebar (group order is in
    // PlatformPanelProvider::NAV_GROUPS).
    'nav' => [
        'overview' => 'Overview',
        'accounts' => 'Accounts',
        'sites' => 'Sites',
        'ai' => 'AI',
        'observability' => 'Observability',
        'controls' => 'Controls',
    ],

    // Queue & worker health widget (dashboard).
    'health' => [
        'worker' => 'Worker',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'worker_ok' => 'Horizon is processing jobs',
        'worker_down' => 'No worker running — jobs will not process',
        'pending' => 'Pending jobs',
        'pending_sub' => 'Waiting in the queues',
        'failed' => 'Failed jobs',
        'failed_sub' => 'Open Horizon to inspect / retry',
    ],

    // P1 — Costs-vs-revenue dashboard (overview).
    'costs' => [
        'title' => 'Costs vs revenue',
        'heading' => 'Platform margin',
        'window' => 'Last :days days',
        'kpi' => [
            'revenue' => 'Revenue billed',
            'cost' => 'OpenRouter cost',
            'margin' => 'Gross margin',
            'markup' => 'Markup realized',
            'charges' => 'Charges',
            'accounts' => 'Active accounts',
        ],
        'summary' => [
            'title' => 'Cost vs revenue',
            'sub' => 'Selling value billed to merchants against the real OpenRouter spend behind it.',
            'revenue' => 'Revenue',
            'cost' => 'Cost',
            'margin' => 'Margin',
            'target' => 'Target markup :value×',
            'realized' => 'Realized :value×',
            'on_target' => 'On target',
            'below_target' => 'Below target',
            'margin_ratio' => ':value% of revenue',
        ],
        'empty' => 'No charges in this window yet',
        'empty_sub' => 'Cost and revenue appear once merchants run paid generations.',
    ],

    // P2 — Accounts.
    'accounts' => [
        'title' => 'Accounts',
        'singular' => 'Account',
        'add' => 'Add account',
        'col' => [
            'name' => 'Account',
            'status' => 'Status',
            'balance' => 'Balance',
            'reserved' => 'Reserved',
            'sites' => 'Sites',
            'created' => 'Joined',
            'owner' => 'Owner',
        ],
        'status' => [
            'active' => 'Active',
            'suspended' => 'Suspended',
        ],
        'section' => [
            'overview' => 'Account overview',
            'credit' => 'Credit',
            'meta' => 'Details',
        ],
        'field' => [
            'name' => 'Account name',
            'company' => 'Company',
            'billing_email' => 'Billing email',
            'locale' => 'Locale',
            'spendable' => 'Spendable',
        ],
        'locale' => [
            'en' => 'English',
            'he' => 'Hebrew',
        ],
        'create' => [
            'section' => 'Account',
            'owner_section' => 'Owner login',
            'owner_help' => 'The owner signs in to the merchant panel with these.',
            'owner_name' => 'Owner name',
            'owner_email' => 'Owner email',
            'owner_password' => 'Temporary password',
            'owner_password_help' => 'At least 8 characters. Share it with the owner so they can sign in.',
            'saved' => 'Account created',
        ],
        'edit' => [
            'label' => 'Edit',
            'saved' => 'Account updated',
        ],
        'empty' => 'No accounts yet',
        'empty_sub' => 'Merchant accounts appear here as they sign up.',
        'filter' => [
            'status' => 'Status',
        ],
        // Suspend / restore control-plane actions.
        'suspend' => [
            'label' => 'Suspend',
            'modal' => 'Suspend this account?',
            'body' => 'New generations are blocked while suspended. Existing data is kept.',
            'reason' => 'Reason (optional)',
            'reason_placeholder' => 'Why this account is being suspended',
            'confirm' => 'Suspend account',
            'done' => 'Account suspended',
            'noop' => 'Account is already suspended',
        ],
        'restore' => [
            'label' => 'Restore',
            'modal' => 'Restore this account?',
            'body' => 'The account can run generations again.',
            'confirm' => 'Restore account',
            'done' => 'Account restored',
            'noop' => 'Account is already active',
        ],
        // Manual credit adjustment action.
        'adjust' => [
            'label' => 'Adjust credit',
            'modal' => 'Adjust account credit',
            'body' => 'Writes one append-only adjustment ledger row. Downward moves are floored at a zero balance.',
            'amount' => 'Amount (USD)',
            'amount_help' => 'Positive grants credit; negative claws it back.',
            'reference' => 'Reference (optional)',
            'reference_help' => 'A stable reference makes a re-submit idempotent.',
            'description' => 'Description',
            'description_placeholder' => 'Why this adjustment was made',
            'confirm' => 'Apply adjustment',
            'done' => 'Credit adjusted',
            'result' => 'New balance :balance (:delta)',
        ],
    ],

    // P3 — Sites (cross-account, full CRUD via audited seams).
    'sites' => [
        'title' => 'Sites',
        'singular' => 'Site',
        'add' => 'Add site',
        'edit' => 'Edit',
        'saved' => 'Site created',
        'updated' => 'Site saved',
        'embed' => [
            'label' => 'Install code',
            'heading' => 'Install snippet',
            'close' => 'Close',
        ],
        'field' => [
            'account' => 'Account',
            'name' => 'Site name',
            'domain' => 'Domain',
            'domain_placeholder' => 'https://shop.example.com',
            'origins' => 'Allowed origins',
            'origins_help' => 'The widget only runs on these origins.',
        ],
        'col' => [
            'name' => 'Site',
            'account' => 'Account',
            'domain' => 'Domain',
            'no_domain' => 'No domain set',
            'state' => 'Setup',
            'created' => 'Created',
        ],
        'state' => [
            'ready' => 'Ready',
            'pending' => 'Setup pending',
        ],
        'empty' => 'No sites yet',
        'empty_sub' => 'Merchant storefronts appear here across every account.',
        'filter' => [
            'account' => 'Account',
        ],

        // Per-site scan review/confirm (the ManageSiteProducts page).
        'products' => [
            'label' => 'Products',
            'title' => 'Products — :site',
            'col' => [
                'name' => 'Product',
                'status' => 'Status',
                'confidence' => 'Confidence',
                'variants' => 'Variants',
                'created' => 'Scanned',
            ],
            'status' => [
                'draft' => 'Needs review',
                'confirmed' => 'Confirmed',
                'failed' => 'Scan failed',
            ],
            'review' => [
                'label' => 'Review',
                'heading' => 'Scanned product',
                'sub' => 'What the scan read from the page. Read-only — verify before confirming.',
                'items' => ':count item(s)',
            ],
            'confirm' => [
                'label' => 'Confirm',
                'heading' => 'Confirm this product?',
                'sub' => 'Confirms the product exactly as scanned (no corrections). It goes live for the widget.',
                'submit' => 'Confirm product',
                'done' => 'Product confirmed',
                'blocked' => 'Still blocked: :count scan row(s) need review',
            ],
            'empty' => 'No scanned products yet',
            'empty_sub' => 'Use "Scan a product" on the site to add one.',
        ],

        // Per-site "is everything configured?" verify checklist.
        'verify' => [
            'label' => 'Verify setup',
            'heading' => 'Setup status',
            'sub' => 'What must be in place for the Tray On button to appear on this site.',
            'close' => 'Close',
            'check' => [
                'openrouter' => 'OpenRouter API key',
                'origins' => 'Allowed origins',
                'product' => 'A confirmed product',
                'snippet' => 'Install snippet on the product page',
            ],
            'hint' => [
                'openrouter' => 'Set the platform OpenRouter key in Settings — every scan and generation needs it.',
                'origins' => 'The widget only runs on the site\'s allowed origins. Add at least one.',
                'product' => 'Scan a product and confirm it. The widget needs a confirmed product to work.',
                'snippet' => 'Add the install snippet (Install code) to the store product page — this cannot be checked here.',
            ],
        ],
    ],

    // P4 — AI models catalog.
    'models' => [
        'title' => 'AI models',
        'singular' => 'Model',
        'add' => 'Add model',
        'col' => [
            'model_id' => 'Model ID',
            'label' => 'Label',
            'operation' => 'Operation',
            'default' => 'Default',
            'fallback' => 'Fallback',
            'cost_hint' => 'Cost hint',
            'active' => 'Active',
        ],
        'field' => [
            'model_id' => 'Model ID',
            'model_id_help' => 'The exact OpenRouter model id (e.g. google/gemini-2.5-flash).',
            'label' => 'Label',
            'operation' => 'Operation',
            'is_default' => 'Default for operation',
            'is_fallback' => 'Fallback for operation',
            'cost_hint' => 'Cost hint',
            'cost_unit' => 'Cost unit',
            'is_active' => 'Active',
        ],
        'unit' => [
            'per_image' => 'Per image',
            'per_1k_tokens' => 'Per 1K tokens',
        ],
        'empty' => 'No models in the catalog',
        'empty_sub' => 'Add the OpenRouter models each operation may use.',
        'filter' => [
            'operation' => 'Operation',
        ],
    ],

    // P5 — Prompts editor + resolver preview.
    'prompts' => [
        'title' => 'Prompts',
        'singular' => 'Prompt',
        'add' => 'Add prompt',
        'col' => [
            'scope' => 'Scope',
            'operation' => 'Operation',
            'product_type' => 'Product type',
            'account' => 'Account',
            'global' => 'Global floor',
            'version' => 'v:version',
            'active' => 'Active',
        ],
        'section' => [
            'scope' => 'Scope & operation',
            'template' => 'Template',
        ],
        'field' => [
            'scope' => 'Scope',
            'operation' => 'Operation',
            'product_type' => 'Product type',
            'product_type_help' => 'Only for product_type-scoped prompts.',
            'account_id' => 'Account',
            'site_id' => 'Site',
            'system' => 'System prompt',
            'user' => 'User prompt',
            'user_help' => 'Use {{placeholders}} — substituted with strtr, never evaluated.',
            'version' => 'Version',
            'is_active' => 'Active',
        ],
        'scope' => [
            'global' => 'Global',
            'product_type' => 'Product type',
            'account' => 'Account',
            'site' => 'Site',
        ],
        'empty' => 'No prompts yet',
        'empty_sub' => 'Every operation needs at least a global prompt (the floor).',
        'filter' => [
            'scope' => 'Scope',
            'operation' => 'Operation',
        ],
    ],

    // The resolver-preview panel on the prompt edit page.
    'resolver' => [
        'title' => 'Resolution preview',
        'sub' => 'Which model + prompt this operation resolves to, and why. Read-only — no model call, no write.',
        'preview' => 'Preview resolved',
        'winner' => 'Winning prompt',
        'trace' => 'Resolution order',
        'fellthrough' => 'Fell through to the global floor',
        'input' => [
            'operation' => 'Operation',
            'site' => 'Site (optional)',
            'site_none' => 'No site (global / product_type only)',
            'product_type' => 'Product type (optional)',
        ],
        'model' => [
            'title' => 'Model',
            'winning' => 'Winning model',
            'fallback' => 'Fallback',
            'chain' => 'Try order',
            'none_fallback' => 'No fallback',
        ],
        'prompt' => [
            'title' => 'Prompt',
            'level' => 'Won at',
            'version' => 'Version',
            'id' => 'Prompt #:id',
            'system' => 'System prompt',
            'user' => 'User prompt',
        ],
        'render' => [
            'title' => 'Sample substitution',
            'sub' => 'The winning user prompt with sample variables substituted (strtr, escaped).',
            'no_vars' => 'No placeholders in this prompt.',
        ],
        'config' => [
            'title' => 'Resolved config',
            'quality' => 'Image quality',
            'aspect' => 'Aspect ratio',
            'multiplier' => 'Credit multiplier',
            'multiplier_default' => 'Default markup',
        ],
        'outcome' => [
            'won' => 'Won',
            'no_match' => 'No match',
            'not_reached' => 'Not reached',
            'skipped' => 'Skipped',
        ],
        'state' => [
            'idle' => 'Pick an operation and preview the resolution.',
            'resolving' => 'Resolving…',
            'error' => "Couldn't resolve this operation.",
        ],
    ],

    // P6 — AI operations config.
    'operations' => [
        'title' => 'AI operations',
        'singular' => 'Operation',
        'col' => [
            'operation' => 'Operation',
            'default_model' => 'Default model',
            'fallback_model' => 'Fallback',
            'quality' => 'Quality',
            'aspect' => 'Aspect',
            'multiplier' => 'Multiplier',
        ],
        'section' => [
            'operation' => 'Operation',
            'models' => 'Models',
            'image' => 'Image & retention',
            'pricing' => 'Pricing',
        ],
        'field' => [
            'operation_key' => 'Operation key',
            'label' => 'Label',
            'default_model' => 'Default model',
            'fallback_model' => 'Fallback model',
            'quality' => 'Image quality',
            'aspect' => 'Aspect ratio',
            'retention' => 'Retention (days)',
            'retention_help' => 'How long generated media is kept for this operation.',
            'estimated_cost' => 'Estimated cost (USD)',
            'multiplier' => 'Credit multiplier override',
            'multiplier_help' => 'Overrides the default markup for this operation when set.',
        ],
        'empty' => 'No operations configured',
        'empty_sub' => 'The control plane must define product_scan and try_on_generation.',
    ],

    // P7 — Credits admin (read-only ledger).
    'credits' => [
        'title' => 'Credit ledger',
        'singular' => 'Ledger row',
        'grant' => 'Grant credits',
        'adjust' => 'Adjust balance',
        'col' => [
            'account' => 'Account',
            'type' => 'Type',
            'amount' => 'Amount',
            'balance_after' => 'Balance after',
            'reference' => 'Reference',
            'cost' => 'Cost',
            'date' => 'Date',
        ],
        'reference' => [
            'generation' => 'Generation #:id',
            'purchase' => 'Purchase #:id',
            'none' => '—',
        ],
        'empty' => 'No ledger rows yet',
        'empty_sub' => 'Grants, purchases, charges, refunds and adjustments appear here across every account.',
        'filter' => [
            'type' => 'Type',
            'account' => 'Account',
        ],
    ],

    // P8 — Observability / activity logs.
    'logs' => [
        'title' => 'Activity log',
        'singular' => 'Event',
        'col' => [
            'account' => 'Account',
            'kind' => 'Event',
            'actor' => 'Actor',
            'subject' => 'Subject',
            'date' => 'When',
        ],
        'actor' => [
            'system' => 'System',
            'merchant' => 'Merchant',
            'end_user' => 'Shopper',
            'webhook' => 'Webhook',
        ],
        'subject' => [
            'none' => '—',
        ],
        'empty' => 'No activity yet',
        'empty_sub' => 'Money-path and control-plane events appear here across every account.',
        'filter' => [
            'kind' => 'Event',
            'actor' => 'Actor',
            'account' => 'Account',
        ],
    ],

    // Controls — platform-wide settings (secrets managed from the UI).
    'settings' => [
        'nav' => 'Settings',
        'title' => 'Platform settings',
        'save' => 'Save settings',
        'saved' => 'Settings saved',
        'secret_help' => 'Leave blank to keep the current value. Stored encrypted; never shown again.',
        'status' => [
            'configured' => 'Configured',
            'unset' => 'Not set',
        ],
        'openrouter' => [
            'title' => 'OpenRouter',
            'sub' => 'The server-side API key used for every product scan and try-on generation.',
            'api_key' => 'OpenRouter API key',
        ],
        'payplus' => [
            'title' => 'PayPlus (payments)',
            'sub' => 'Credentials for the credit-purchase rail. Fill these in when you enable paid top-ups.',
            'api_key' => 'API key',
            'secret_key' => 'Secret key',
            'page_uid' => 'Payment page UID',
            'webhook_secret' => 'Webhook secret',
        ],
    ],
];
