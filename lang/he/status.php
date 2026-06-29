<?php

// === KEYS: status.* — מפת תגיות §5. מקור EN: lang/en/status.php ===
// הסטטוסים הם ערכי מכונת-המצב הקנוניים מ-ARCHITECTURE.md — לעולם לא מילה נרדפת.

return [
    'generation' => [
        'pending' => 'ממתין',
        'processing' => 'בעיבוד',
        'succeeded' => 'הצליח',
        'failed' => 'נכשל',
        'cancelled' => 'בוטל',
    ],
    'ledger' => [
        'grant' => 'זיכוי',
        'purchase' => 'רכישה',
        'charge' => 'חיוב',
        'refund' => 'החזר',
        'adjustment' => 'התאמה',
    ],
    'credit' => [
        'low' => 'נמוך',
        'empty' => 'אזל',
    ],
    'lead' => [
        'new' => 'חדש',
        'generated' => 'יצר תמונה',
        'added_to_cart' => 'הוסיף לעגלה',
        'purchased' => 'רכש',
        'incomplete' => 'לא הושלם',
    ],
];
