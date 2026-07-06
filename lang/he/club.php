<?php

/*
 * Hebrew mirror of lang/en/club.php — 1:1 key parity is a release blocker
 * (i18n-catalog mirror-integrity rule). Every key present in `en` is present here.
 */

return [

    'mail' => [
        'dir' => 'rtl',
        'subject' => 'קוד האימות שלך למועדון',
        'heading' => 'אמתו את האימייל שלכם כדי להצטרף למועדון',
        'intro' => 'הזינו את הקוד הזה בחנות כדי לאמת את האימייל ולפתוח מחירי חברי מועדון.',
        'expiry' => 'תוקף הקוד יפוג בעוד :minutes דקות.',
        'ignore' => 'אם לא ביקשתם זאת, אפשר להתעלם מהודעה זו.',
    ],

    'verify' => [
        'invalid' => 'הקוד שגוי. נסו שוב.',
        'expired' => 'תוקף הקוד פג. בקשו קוד חדש.',
        'locked' => 'יותר מדי ניסיונות. בקשו קוד חדש.',
    ],

    'request' => [
        'throttled' => 'המתינו רגע לפני בקשת קוד נוסף.',
    ],
];
