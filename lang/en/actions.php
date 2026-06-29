<?php

// === KEYS: actions.* — admin action labels. Mirror: lang/he/actions.php ===
//
// NOTE: the catalog lists both `actions.confirm` (the "Confirm" label) and
// `actions.confirm.delete_title`/`delete_body`. PHP can't hold a key as both a
// string and a nested array, so the destructive-confirm copy lives under
// `actions.confirm_delete.*`. Flagged to product-ux-architect as a catalog
// collision; resolved here without losing any string.

return [
    'save' => 'Save',
    'cancel' => 'Cancel',
    'confirm' => 'Confirm',
    'delete' => 'Delete',
    'edit' => 'Edit',
    'working' => 'Working…',
    'dismiss' => 'Dismiss',
    'clear_filters' => 'Clear filters',
    'confirm_delete' => [
        'title' => 'Delete this?',
        'body' => "This can't be undone.",
    ],
];
