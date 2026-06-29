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
    ],
];
