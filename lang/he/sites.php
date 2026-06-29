<?php

// === KEYS: sites.* — אתרי הסוחר. מקור EN: lang/en/sites.php ===

return [
    'title' => 'אתרים',
    'singular' => 'אתר',
    'add' => 'הוספת אתר',
    'saved' => 'האתר נוסף',
    'updated' => 'האתר נשמר',
    'empty' => 'הוסיפו אתר ראשון כדי להתחיל',
    'empty_sub' => 'אתר הוא חנות אחת שבה רץ הווידג׳ט של Tray On.',
    'field' => [
        'domain' => 'דומיין',
        'domain_placeholder' => 'https://shop.example.com',
        'name' => 'שם תצוגה',
        'origins' => 'דומיינים מורשים',
        'origins_placeholder' => 'https://shop.example.com',
        'origins_help' => 'הווידג׳ט יפעל רק בדומיינים אלו.',
    ],
    'col' => [
        'name' => 'שם',
        'domain' => 'דומיין',
        'no_domain' => 'לא הוגדר דומיין',
        'status' => 'סטטוס',
        'created' => 'נוסף',
    ],
    'status' => [
        'ready' => 'מוכן',
        'pending' => 'ממתין להגדרה',
    ],
    'action' => [
        'edit' => 'עריכה',
        'embed' => 'קוד התקנה',
        'products' => 'מוצרים',
        'review' => 'בדיקה',
    ],
    'settings' => [
        'title' => 'הגדרות אתר',
    ],
    'products' => [
        'title' => 'מוצרים',
        'singular' => 'מוצר',
        'empty' => 'עדיין לא נסרקו מוצרים',
        'empty_sub' => 'סרקו עמוד מוצר כדי להוסיף את המוצר הראשון.',
        'col' => [
            'name' => 'מוצר',
            'status' => 'סטטוס',
            'confidence' => 'ביטחון',
            'scanned' => 'נסרק',
        ],
        'status' => [
            'draft' => 'דורש בדיקה',
            'confirmed' => 'פעיל',
            'failed' => 'הסריקה נכשלה',
        ],
    ],
    'errors' => [
        'duplicate' => 'כבר קיים אתר עם דומיין זה',
        'invalid_domain' => 'הזינו דומיין תקין',
    ],
];
