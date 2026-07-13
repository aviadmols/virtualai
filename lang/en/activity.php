<?php

// === KEYS: activity.* — activity-event KIND labels for the platform log.
// One key per ActivityEvent::KIND_* constant. Mirror: lang/he/activity.php ===

return [
    'kind' => [
        // Credit / money-path traces.
        'opening_grant' => 'Opening grant',
        'credit_grant' => 'Credit granted',
        'credit_charged' => 'Credit charged',
        'credit_refunded' => 'Credit refunded',
        'credit_adjusted' => 'Credit adjusted',
        'credit_reservation_released' => 'Reservation released',
        'credit_gate_blocked' => 'Credit gate blocked',

        // Lead funnel.
        'lead_gate_blocked' => 'Lead gate blocked',
        'lead_registered' => 'Lead registered',
        'lead_added_to_cart' => 'Added to cart',

        // Widget behavioral events.
        'page_view' => 'Page viewed',
        'interaction' => 'Interaction',

        // Account control-plane actions.
        'account_suspended' => 'Account suspended',
        'account_restored' => 'Account restored',

        // Site control-plane actions.
        'site_key_regenerated' => 'Site key regenerated',
        'site_settings_updated' => 'Site settings updated',

        // Generation pipeline.
        'generation_requested' => 'Generation requested',
        'generation_reserved' => 'Generation reserved',
        'generation_processing' => 'Generation processing',
        'generation_succeeded' => 'Generation succeeded',
        'generation_failed' => 'Generation failed',
        'generation_cancelled' => 'Generation cancelled',
        'generation_status_changed' => 'Generation status changed',

        // Shopify app + product sync.
        'shopify_installed' => 'Shopify store connected',
        'shopify_uninstalled' => 'Shopify store disconnected',
        'shopify_sync_started' => 'Product import started',
        'shopify_sync_completed' => 'Product import finished',
        'shopify_sync_failed' => 'Product import failed',
        'shopify_sync_truncated' => 'Product import stopped early (partial catalog)',
        'shopify_product_imported' => 'Product imported from Shopify',
        'shopify_product_updated' => 'Product updated from Shopify',
        'shopify_product_archived' => 'Product archived (removed from Shopify)',

        // Product Image Studio (bulk AI image generation + review).
        'product_image_batch_started' => 'Image batch started',
        'product_image_batch_completed' => 'Image batch finished',
        'product_asset_status_changed' => 'Product image status changed',
        'product_asset_approved' => 'Product image approved',
        'product_asset_rejected' => 'Product image rejected',
    ],

    // Per-end-user activity timeline on the merchant lead card (WS3).
    'timeline' => [
        'title' => 'Activity timeline',
        'subtitle' => 'Everything this shopper did on your shop.',
        'empty' => 'No activity yet',
    ],
];
