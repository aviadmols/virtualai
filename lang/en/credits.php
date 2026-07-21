<?php

// === KEYS: credits.* — ledger view + buy credits (M7). Mirror: lang/he/credits.php ===

return [
    'balance' => 'Credit balance',

    // The persistent topbar credit chip + Buy CTA.
    'topbar' => [
        'balance' => 'Your spendable credit',
        'buy' => 'Buy credits',
    ],

    // A1 balance band on the ledger page.
    'kpi' => [
        'spendable' => 'Spendable credit',
        'balance' => 'Balance',
        'reserved' => 'Reserved',
        'spendable_sub' => 'Balance minus reserved',
        'reserved_sub' => 'Held for in-flight try-ons',
    ],

    'title' => 'Credits',
    'singular' => 'Transaction',

    'ledger' => [
        'title' => 'Ledger',
        'empty' => 'No transactions yet',
        'empty_sub' => 'Your transactions will appear here as you generate try-ons and top up.',
        'col' => [
            'date' => 'Date',
            'type' => 'Type',
            'amount' => 'Amount',
            'balance_after' => 'Balance after',
            'reference' => 'Reference',
        ],
        'reference' => [
            'generation' => 'Try-on #:id',
            'purchase' => 'Top-up #:id',
            'none' => '—',
        ],
        'filter' => [
            'type' => 'Type',
        ],
    ],

    'buy' => [
        'title' => 'Buy credits',
        'nav' => 'Buy credits',
        'heading' => 'Top up your credits',
        'sub' => 'Credits are added at face value. The markup is earned only when a try-on is generated.',
        'amount' => 'Amount',
        'choose' => 'Choose an amount',
        'selected' => 'Selected',
        'confirm' => 'Continue to payment',
        'pending' => 'Redirecting to payment…',
        'success' => 'Credits added',
        'no_amount' => 'Pick an amount to continue.',
        'note' => "You'll be redirected to our payment provider. Credits are added only after a successful payment.",
        'errors' => [
            'failed' => "Payment didn't complete. No charge was made.",
            'init' => "Couldn't start the payment. Please try again.",
        ],
    ],
];
