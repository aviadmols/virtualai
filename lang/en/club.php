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
];
