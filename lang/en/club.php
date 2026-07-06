<?php

/*
 * Customer-Club module strings (Phase 2). Backend-facing copy: the verification
 * email + the stable reasons the club endpoints return. The widget (a later agent)
 * renders its own localized screens from the machine `reason` codes; these are the
 * email body + fallback text. `he` mirrors every key 1:1 (release-blocker parity).
 */

return [

    // The one-time-code email (developer-authored; scalar code/minutes only).
    'mail' => [
        'dir' => 'ltr',
        'subject' => 'Your club verification code',
        'heading' => 'Verify your email to join the club',
        'intro' => 'Enter this code in the store to confirm your email and unlock member pricing.',
        'expiry' => 'This code expires in :minutes minutes.',
        'ignore' => 'If you did not request this, you can safely ignore this email.',
    ],

    // Stable reasons returned by the verify endpoint (verified:false).
    'verify' => [
        'invalid' => 'That code is not correct. Please try again.',
        'expired' => 'That code has expired. Request a new one.',
        'locked' => 'Too many attempts. Request a new code.',
    ],

    // request-code outcomes.
    'request' => [
        'throttled' => 'Please wait a moment before requesting another code.',
    ],

    // --- Merchant Club-settings page (Phase 2b-UI) ---
    'settings' => [
        'nav' => 'Customer Club',
        'title' => 'Customer Club',
        'heading' => 'Customer Club',
        'sub' => 'Set up member pricing for :site.',
        'saved' => 'Club settings saved.',
        'field' => [
            'enabled' => 'Enable the Customer Club',
            'enabled_help' => 'Show a join banner and member pricing on your store. You can turn this off at any time.',
            'discount_percent' => 'Member discount (%)',
            'discount_percent_help' => 'A whole number from 0 to 100. Members see this much off the shown price. Display-only for now — checkout is unchanged.',
        ],
        'errors' => [
            'save_failed' => 'Could not save your club settings. Please try again.',
            'invalid_club_config' => 'Some club values are out of range. Check the discount (0–100), the banner timing, and the picked price zones, then save again.',
        ],
    ],

    // --- Banner behavior + timing (Phase 2c) ---
    'behavior' => [
        'section' => 'Banner behavior',
        'section_help' => 'Choose when and where the join banner appears, and how long a dismissal is remembered.',
        'trigger' => 'When to show the banner',
        'trigger_help' => 'Show it right away, after a short delay, or once the shopper scrolls down the page.',
        'trigger_option' => [
            'immediate' => 'Right away',
            'delay' => 'After a delay',
            'scroll' => 'After scrolling',
        ],
        'delay_seconds' => 'Delay (seconds)',
        'delay_seconds_help' => 'How long after the page loads before the banner appears (0–60 seconds).',
        'scroll_percent' => 'Scroll depth (%)',
        'scroll_percent_help' => 'Show the banner once the shopper has scrolled this far down the page (1–100%).',
        'position' => 'Banner position',
        'position_help' => 'Which corner of the screen the banner sits in.',
        'position_option' => [
            'bottom-end' => 'Bottom right',
            'bottom-start' => 'Bottom left',
            'top-end' => 'Top right',
            'top-start' => 'Top left',
        ],
        'dismiss_days' => 'Remember dismissal (days)',
        'dismiss_days_help' => "If a shopper closes the banner, don't show it again for this many days (0 = show again on their next visit).",
    ],

    // --- The per-surface price-zone picker (multi-pick) ---
    'zones' => [
        'section' => 'Where the member price shows',
        'section_help' => 'Pick the price element on each surface. You can pick more than one per surface.',
        'surface' => [
            'pdp' => 'Product page',
            'catalog' => 'Catalog / collection',
            'cart' => 'Cart',
        ],
        'count' => '{0}No zones picked|{1}:count zone|[2,*]:count zones',
        'empty' => 'No price zones picked yet.',
        'pick' => 'Pick visually',
        'remove' => 'Remove this zone',
        'eyebrow' => 'Customer Club',
        'modal_title' => 'Pick the price on the :surface',
        'from_scan' => 'Previewing your scanned product',
        'previewing' => 'Previewing',
        'close' => 'Close',
        'done' => 'Done',
        'url_placeholder' => 'Paste a page URL to preview (needed for catalog & cart)',
        'load' => 'Load preview',
        'loading' => 'Loading…',
        'preview' => 'Store preview',
        'load_hint' => 'Paste a URL from this surface and load a live preview, then click each price element.',
        'hint' => 'Click every price element you want the member price shown on.',
        'picked_label' => 'Picked price zones',
        'none_yet' => 'Nothing picked yet — click a price in the preview.',
        'verdict' => [
            'added' => 'Added — this price element is now a member-price zone.',
            'duplicate' => 'You already picked this element.',
            'full' => 'That surface already has the maximum of :max zones.',
            'multiple' => 'That selector matches :count elements. Pick a single, specific price.',
            'none' => 'That selector matched nothing on the page. Try another element.',
        ],
        'errors' => [
            'load_failed' => 'Could not load a preview of that page. Try another URL.',
            'bad_url' => 'That does not look like a valid URL.',
            'rate_limited' => 'Too many preview attempts. Please wait a minute and try again.',
        ],
    ],
];
