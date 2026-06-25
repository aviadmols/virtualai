<?php

/*
 * Server-side messages for the signed widget API (Phase 7a).
 *
 * These are the BACKEND-facing messages the widget API returns alongside a stable
 * machine `reason` / `code`. The widget (Phase 7b) renders its OWN localized copy from
 * the reason code via the widget i18n catalog (docs/ux/i18n-catalog.md `widget.*`); these
 * strings are the fallback / validation-error text. `he` mirrors every key 1:1.
 */

return [

    // Auth / middleware rejections (typed JSON, never a 500/HTML to the widget).
    'auth' => [
        'unknown_site' => 'Unknown or inactive site key.',
        'origin_not_allowed' => 'This origin is not allowed for this site.',
        'signature_required' => 'A request signature is required.',
        'signature_invalid' => 'The request signature is invalid.',
        'signature_expired' => 'The request signature has expired.',
    ],

    // Rate limiting.
    'rate_limited' => 'Too many requests. Please slow down and try again shortly.',

    // Gate denials (typed business outcomes — NOT errors, NEVER a charge).
    'gates' => [
        'signup_required' => 'Sign up to keep generating try-ons.',
        'post_signup_limit_reached' => 'You have reached your try-on limit.',
        'insufficient_credits' => 'Try-on is not available right now.',
        'account_inactive' => 'Try-on is not available right now.',
    ],

    // Start-generation input problems (the widget must fix these).
    'start' => [
        'photo_consent_required' => 'Please agree to let us use your photo before generating a try-on.',
        'product_not_confirmed' => 'This product is not available for try-on yet.',
        'variant_mismatch' => 'The selected variant does not belong to this product.',
    ],

    // Validation field messages.
    'validation' => [
        'anon_token_required' => 'A session token is required.',
        'photo_required' => 'A photo is required.',
        'photo_mime' => 'Please use a JPG, PNG, or WebP image.',
        'photo_size' => 'That image is too large.',
        'photo_invalid' => 'That photo could not be read. Please try another.',
        'height_range' => 'Enter a height between :min and :max cm.',
        'consent_required' => 'Please agree to the terms before generating a try-on.',
        'product_required' => 'A product is required.',
        'variant_required' => 'A variant is required.',
        'client_request_id_required' => 'A request id is required.',
        'email_required' => 'A valid email is required.',
        'name_required' => 'Your name is required.',
    ],

    // Not-found / empty shapes.
    'not_found' => [
        'product' => 'No try-on product is available for this page.',
        'generation' => 'That try-on could not be found.',
    ],
];
