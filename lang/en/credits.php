<?php

// === KEYS: credits.* — ledger view + buy credits. Mirror: lang/he/credits.php ===

return [
    'balance' => 'Credit balance',
    'ledger' => [
        'empty' => 'No transactions yet',
        'col' => [
            'date' => 'Date',
            'type' => 'Type',
            'amount' => 'Amount',
            'balance_after' => 'Balance after',
            'reference' => 'Reference',
        ],
    ],
    'buy' => [
        'title' => 'Buy credits',
        'amount' => 'Amount',
        'confirm' => 'Continue to payment',
        'pending' => 'Redirecting to payment…',
        'success' => 'Credits added',
        'errors' => [
            'failed' => "Payment didn't complete. No charge was made.",
        ],
    ],
];
