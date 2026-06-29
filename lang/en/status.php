<?php

// === KEYS: status.* — the §5 badge map. Mirror: lang/he/status.php ===
// Statuses are the canonical ARCHITECTURE.md state-machine values — never a synonym.

return [
    'generation' => [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],
    'ledger' => [
        'grant' => 'Grant',
        'purchase' => 'Purchase',
        'charge' => 'Charge',
        'refund' => 'Refund',
        'adjustment' => 'Adjustment',
    ],
    'credit' => [
        'low' => 'Low',
        'empty' => 'Out of credits',
    ],
    'lead' => [
        'new' => 'New',
        'generated' => 'Generated',
        'added_to_cart' => 'Added to cart',
        'purchased' => 'Purchased',
        'incomplete' => 'Incomplete',
    ],
];
