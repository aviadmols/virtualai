<?php

// === KEYS: platform.* — מערכת ניהול-העל. מקור EN: lang/en/platform.php ===

return [
    'title' => 'ניהול פלטפורמה',

    // תוויות קבוצות ניווט לסרגל הפלטפורמה (סדר הקבוצות ב-PlatformPanelProvider).
    'nav' => [
        'overview' => 'סקירה',
        'accounts' => 'חשבונות',
        'sites' => 'אתרים',
        'ai' => 'בינה מלאכותית',
        'observability' => 'ניטור',
        'controls' => 'בקרות',
    ],

    'models' => [
        'title' => 'מודלים',
        'col' => [
            'model_id' => 'מזהה מודל',
            'operation' => 'פעולה',
            'default' => 'ברירת מחדל',
            'fallback' => 'גיבוי',
            'cost_hint' => 'עלות משוערת',
        ],
    ],
    'prompts' => [
        'title' => 'פרומפטים',
        'field' => [
            'scope' => 'היקף',
            'operation' => 'פעולה',
            'product_type' => 'סוג מוצר',
            'system' => 'פרומפט מערכת',
            'user' => 'פרומפט משתמש',
            'version' => 'גרסה',
        ],
    ],
    'operations' => [
        'title' => 'פעולות AI',
        'field' => [
            'quality' => 'איכות תמונה',
            'aspect' => 'יחס תצוגה',
            'retention' => 'מדיניות שמירה',
            'multiplier' => 'מכפיל קרדיט',
        ],
    ],
    'accounts' => [
        'title' => 'חשבונות',
    ],
    'sites' => [
        'title' => 'אתרים',
    ],
    'credits' => [
        'grant' => 'הענקת קרדיטים',
        'adjust' => 'התאמת יתרה',
    ],
    'resolver' => [
        'preview' => 'תצוגה מקדימה של ההכרעה',
        'winner' => 'פרומפט נבחר',
        'trace' => 'סדר הכרעה',
        'fellthrough' => 'נפל לברירת מחדל גלובלית',
    ],
];
