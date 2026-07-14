<?php

// === KEYS: shopify.* — the merchant "Shopify store" screen. Mirror: lang/he/shopify.php ===

return [

    'nav' => 'Shopify',
    'title' => 'Shopify store',

    'connected' => [
        'heading' => 'Connected store',
        'sub' => 'Vsio reads this store\'s products through the Shopify Admin API and can push AI-generated images back to product media.',
        'shop' => 'Store domain',
        'installed_at' => 'Connected on',
        'scopes' => 'Granted permissions',
        'api_version' => 'Admin API version',
        'status' => 'Status',
    ],

    'status' => [
        'installed' => 'Connected',
        'uninstalled' => 'Disconnected',
        'needs_reauth' => 'Reconnect needed',
    ],

    'disconnected' => [
        'heading' => 'Connect your Shopify store',
        'sub' => 'Connect the store to import products automatically, keep them in sync, and push approved AI images straight to your product media. No page scanning needed.',
        'cta' => 'Connect a Shopify store',
    ],

    'connect' => [
        'action' => 'Connect store',
        'shop' => 'Store domain',
        'shop_help' => 'Your myshopify.com domain — for example: my-store.myshopify.com',
        'shop_placeholder' => 'my-store.myshopify.com',
        'submit' => 'Continue to Shopify',
        'invalid_shop' => 'That is not a valid myshopify.com store domain.',
    ],

    'disconnect' => [
        'action' => 'Disconnect',
        'confirm_heading' => 'Disconnect this Shopify store?',
        'confirm_sub' => 'Vsio stops syncing products and can no longer push images to this store. The access token is deleted. Your existing products, try-ons and gallery stay intact — reconnect any time.',
        'confirm_cta' => 'Disconnect',
        'done' => 'Store disconnected',
    ],

    'reauth' => [
        'heading' => 'Shopify needs you to reconnect',
        'sub' => 'The store\'s access token is no longer valid, so product sync and image push are paused. Reconnect to resume.',
        'cta' => 'Reconnect',
    ],

    'not_configured' => [
        'heading' => 'The Shopify app is not configured yet',
        'sub' => 'The platform has no Shopify app credentials set, so a store cannot be connected right now. Please contact support.',
    ],

    'webhooks' => [
        'heading' => 'Live updates',
        'sub' => 'Shopify notifies Vsio when a product changes, an order is paid, or the app is removed.',
        'registered' => 'Subscribed topics',
        'none' => 'No topics are subscribed yet — they are registered right after connecting.',
        'last_event' => 'Last event received',
        'never' => 'No events yet',
        'failed' => 'Failed events (last :days days)',
        'healthy' => 'All events processed',
    ],

    // === Phase 3 — product import + sync ===
    'products' => [
        'nav' => 'Shopify products',
        'title' => 'Import Shopify products',

        'not_connected' => [
            'heading' => 'Connect your Shopify store first',
            'sub' => 'Once the store is connected, Vsio can import its products straight from Shopify — no page scanning needed.',
        ],

        'import_all' => [
            'action' => 'Import all products',
            'heading' => 'Import the whole catalog?',
            'sub' => 'Vsio will import :count products from your store. They arrive as drafts for you to confirm — nothing goes live on its own.',
            'capped' => 'Your store has :count products, more than the :cap this app imports in one go. Import the first :cap now, or pick specific products instead.',
            'cta' => 'Start import',
        ],

        'import_selected' => [
            'action' => 'Pick products',
            'field' => 'Products',
            'help' => 'Search your Shopify catalog and pick the products you want on Vsio.',
            'cta' => 'Import selected',
            'empty' => 'Pick at least one product to import.',
        ],

        'confirm_all' => [
            'action' => 'Confirm all :count imported',
            'heading' => 'Confirm every imported product?',
            'sub' => ':count imported products are waiting for your confirmation. Their data comes straight from your Shopify store, so there is nothing to correct — confirming makes them live for try-on.',
            'cta' => 'Confirm all',
        ],

        'progress' => [
            'heading' => 'Import in progress',
            'sub' => 'This updates itself — you can leave the page.',
            'status' => 'Status',
            'mode' => 'Type',
            'seen' => 'Products seen',
            'imported' => 'New',
            'updated' => 'Updated',
            'archived' => 'Archived',
        ],

        'catalog' => [
            'heading' => 'Your imported catalog',
            'sub' => 'Products imported from Shopify. A product removed from your store is archived here — your try-on history stays intact.',
            'imported' => 'Imported',
            'draft' => 'Awaiting confirmation',
            'confirmed' => 'Live for try-on',
            'archived' => 'Archived',
        ],

        'history' => [
            'heading' => 'Recent imports',
            'line' => ':status — :imported new, :updated updated, :archived archived, :failed failed',
            'truncated' => '(partial — nothing archived)',
        ],

        // A walk that hit the page budget: part of the catalog is imported, and NOTHING was
        // archived — an incomplete walk says nothing about the products it never reached.
        'truncated' => [
            'heading' => 'Only part of your catalog was imported',
            'badge' => 'Partial import',
            'sub' => 'The last import stopped after :pages pages (:seen products). Nothing was archived, so the products it did not reach are untouched. Start the import again to continue, or pick the products you need.',
        ],

        // The import was REFUSED before anything was queued (no run, no jobs).
        'refused' => [
            'over_cap' => 'Your store has :count products — more than the :cap this app imports in one go. Nothing was imported. Pick the products you want instead, or contact support to raise the limit.',
            'size_unavailable' => 'Shopify did not tell us how many products your store has, so nothing was imported. Please try again in a moment.',
        ],

        'mode' => [
            'catalog' => 'Whole catalog',
            'selection' => 'Selected products',
            'webhook' => 'Live update',
        ],

        'run_status' => [
            'pending' => 'Queued',
            'running' => 'Importing',
            'completed' => 'Done',
            'failed' => 'Failed',
        ],

        'notify' => [
            'queued' => 'Import started',
            'queued_body' => 'Import #:run is running in the background — the counters update here.',
            'confirmed' => ':count products confirmed',
            'blocked' => ':count products still need your review (a missing image or price).',
            'api_error' => 'Shopify could not be reached. Please try again in a moment.',
            'refused' => 'Import not started',
            'selection_truncated' => 'Only part of your selection was imported',
            'selection_truncated_body' => 'One import takes up to :max products, so :dropped of your picks were left out. They were NOT imported — start another import for them.',
        ],
    ],
];
