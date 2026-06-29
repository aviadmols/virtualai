<?php

// === KEYS: activity.* — תוויות KIND לאירועי פעילות ביומן הפלטפורמה.
// מפתח אחד לכל קבוע ActivityEvent::KIND_*. מקור EN: lang/en/activity.php ===

return [
    'kind' => [
        // עקבות קרדיט / מסלול-כסף.
        'opening_grant' => 'מענק פתיחה',
        'credit_grant' => 'הוענק קרדיט',
        'credit_charged' => 'חויב קרדיט',
        'credit_refunded' => 'הוחזר קרדיט',
        'credit_adjusted' => 'הותאם קרדיט',
        'credit_reservation_released' => 'שוחרר שריון',
        'credit_gate_blocked' => 'נחסם בשער הקרדיט',

        // משפך לידים.
        'lead_gate_blocked' => 'נחסם בשער הלידים',
        'lead_registered' => 'נרשם ליד',
        'lead_added_to_cart' => 'הוסיף לעגלה',

        // פעולות בקרה על חשבון.
        'account_suspended' => 'החשבון הושעה',
        'account_restored' => 'החשבון שוחזר',

        // פעולות בקרה על אתר.
        'site_key_regenerated' => 'מפתח האתר חודש',
        'site_settings_updated' => 'הגדרות האתר עודכנו',

        // צינור היצירה.
        'generation_requested' => 'יצירה התבקשה',
        'generation_reserved' => 'יצירה שוריינה',
        'generation_processing' => 'יצירה בעיבוד',
        'generation_succeeded' => 'יצירה הצליחה',
        'generation_failed' => 'יצירה נכשלה',
        'generation_cancelled' => 'יצירה בוטלה',
    ],
];
