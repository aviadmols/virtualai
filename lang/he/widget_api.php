<?php

/*
 * Hebrew mirror of lang/en/widget_api.php — 1:1 key parity is a release blocker
 * (i18n-catalog mirror-integrity rule). Every key present in `en` is present here.
 */

return [

    'auth' => [
        'unknown_site' => 'מפתח אתר לא מוכר או לא פעיל.',
        'origin_not_allowed' => 'המקור הזה אינו מורשה עבור האתר.',
        'signature_required' => 'נדרשת חתימה לבקשה.',
        'signature_invalid' => 'חתימת הבקשה אינה תקינה.',
        'signature_expired' => 'תוקף חתימת הבקשה פג.',
    ],

    'rate_limited' => 'יותר מדי בקשות. האטו מעט ונסו שוב בקרוב.',

    'gates' => [
        'signup_required' => 'הירשמו כדי להמשיך ליצור הדמיות.',
        'post_signup_limit_reached' => 'הגעתם למגבלת ההדמיות שלכם.',
        'insufficient_credits' => 'ההדמיה אינה זמינה כרגע.',
        'account_inactive' => 'ההדמיה אינה זמינה כרגע.',
    ],

    'start' => [
        'photo_consent_required' => 'אנא אשרו את השימוש בתמונה שלכם לפני יצירת ההדמיה.',
        'product_not_confirmed' => 'המוצר הזה עדיין לא זמין להדמיה.',
        'variant_mismatch' => 'הוריאציה שנבחרה אינה שייכת למוצר הזה.',
        'storage_failed' => 'לא הצלחנו לשמור את התמונה כרגע. נסו שוב בעוד רגע.',
    ],

    'validation' => [
        'anon_token_required' => 'נדרש מזהה הפעלה.',
        'photo_required' => 'נדרשת תמונה.',
        'photo_mime' => 'אנא השתמשו בתמונת JPG, PNG או WebP.',
        'photo_size' => 'התמונה גדולה מדי.',
        'photo_invalid' => 'לא ניתן היה לקרוא את התמונה. נסו אחרת.',
        'height_range' => 'הזינו גובה בין :min ל-:max ס"מ.',
        'consent_required' => 'אנא אשרו את התנאים לפני יצירת ההדמיה.',
        'product_required' => 'נדרש מוצר.',
        'variant_required' => 'נדרשת וריאציה.',
        'client_request_id_required' => 'נדרש מזהה בקשה.',
        'email_required' => 'נדרש אימייל תקין.',
        'name_required' => 'נדרש שם.',
    ],

    'not_found' => [
        'product' => 'אין מוצר הדמיה זמין לעמוד הזה.',
        'generation' => 'ההדמיה לא נמצאה.',
    ],
];
