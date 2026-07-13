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

        // אירועי התנהגות בווידג׳ט.
        'page_view' => 'צפייה בעמוד',
        'interaction' => 'אינטראקציה',

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
        'generation_status_changed' => 'סטטוס היצירה השתנה',

        // אפליקציית Shopify וסנכרון מוצרים.
        'shopify_installed' => 'חנות Shopify חוברה',
        'shopify_uninstalled' => 'חנות Shopify נותקה',
        'shopify_sync_started' => 'ייבוא מוצרים התחיל',
        'shopify_sync_completed' => 'ייבוא מוצרים הסתיים',
        'shopify_sync_failed' => 'ייבוא מוצרים נכשל',
        'shopify_sync_truncated' => 'ייבוא המוצרים נעצר מוקדם (קטלוג חלקי)',
        'shopify_product_imported' => 'מוצר יובא מ-Shopify',
        'shopify_product_updated' => 'מוצר עודכן מ-Shopify',
        'shopify_product_archived' => 'מוצר הועבר לארכיון (הוסר מ-Shopify)',

        // סטודיו תמונות המוצר (יצירת תמונות AI בכמות + סקירה).
        'product_image_batch_started' => 'אצוות תמונות התחילה',
        'product_image_batch_completed' => 'אצוות תמונות הסתיימה',
        'product_asset_status_changed' => 'סטטוס תמונת המוצר השתנה',
        'product_asset_approved' => 'תמונת מוצר אושרה',
        'product_asset_rejected' => 'תמונת מוצר נדחתה',
    ],

    // ציר פעילות פר-משתמש-קצה בכרטיס הליד של הסוחר (WS3).
    'timeline' => [
        'title' => 'ציר פעילות',
        'subtitle' => 'כל מה שהקונה עשה בחנות שלך.',
        'empty' => 'אין עדיין פעילות',
    ],
];
