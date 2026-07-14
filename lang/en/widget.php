<?php

// === KEYS: widget.* — storefront widget strings. Mirror: lang/he/widget.php ===
// Counts use trans_choice (widget.tries.left); amounts/dates are locale-formatted.

return [
    'button' => [
        'label' => 'Try it with Vsio',
        'loading' => 'Loading…',
        'busy' => 'Creating…',
    ],
    'modal' => [
        'title' => 'Vsio',
        'close' => 'Close',
    ],
    'upload' => [
        'prompt' => 'Add a photo of yourself',
        'hint' => 'A clear, well-lit photo works best',
        'uploading' => 'Uploading…',
        'replace' => 'Replace photo',
        'remove' => 'Remove',
        'errors' => [
            'type' => 'Please use a JPG or PNG image',
            'size' => 'That image is too large',
            'failed' => 'Upload failed. Try again.',
        ],
    ],
    'height' => [
        'label' => 'Your height',
        'unit_cm' => 'cm',
        'unit_in' => 'in',
        'errors' => [
            'range' => 'Enter a height between :min and :max',
        ],
    ],
    'details' => [
        'toggle' => 'Add details (optional)',
        'body' => 'Body type',
        'age' => 'Age range',
        'gender' => 'Gender',
        'angle' => 'Photo angle',
    ],
    'consent' => [
        'photo' => 'I agree to let Vsio use my photo to generate a virtual try-on of this product.',
        'privacy_link' => 'How we handle your photo',
        'retention' => 'Your photo is kept for :days days, then deleted.',
        'required' => 'Please agree before we generate your try-on',
    ],
    'cta' => [
        'generate' => 'Generate my try-on',
        'need_photo' => 'Add a photo to continue',
        'need_height' => 'Add your height to continue',
        'need_consent' => 'Agree to the terms to continue',
    ],
    'loading' => [
        'title' => 'Creating your try-on…',
        'sub' => 'This takes a few seconds — you can close this and we\'ll let you know',
        'cancel' => 'Cancel',
        'timeout' => 'This is taking longer than usual. Try again?',
    ],
    'notify' => [
        'ready_title' => 'Your try-on is ready',
        'ready_sub' => 'Tap to view it',
        'failed_title' => "Your try-on didn't finish",
        'failed_sub' => 'Tap to try again',
        'opening' => 'Opening your try-on…',
    ],
    'result' => [
        'title' => "Here's your try-on",
        'low_quality' => 'Not quite right? Try again for a better result.',
        'error' => "Something went wrong. You weren't charged — try again.",
        'regenerate' => 'Try again',
        'change_photo' => 'Change photo',
        'change_height' => 'Change height',
        'add_to_cart' => 'Add this to cart',
        'back' => 'Back to product',
    ],
    'cart' => [
        'added' => 'Added to cart',
    ],
    'gallery' => [
        'title' => 'Your previous try-ons',
        'viewing' => 'Your try-on',
        'view' => 'View this try-on',
        'back' => 'Back',
        'empty' => 'Your try-ons will appear here',
        'error' => "Couldn't load your try-ons",
        'open' => 'View full size',
        'add_to_cart' => 'Add to cart',
        'regenerate' => 'Try again',
        'delete' => 'Delete',
        'delete_confirm' => 'Delete this try-on?',
    ],
    'tries' => [
        'left' => '{0} No free tries left|{1} :count free try left|[2,*] :count free tries left',
        'last' => 'Last free try — after this, a quick sign-up keeps you going',
        'exhausted_title' => 'Sign up to keep trying',
        'exhausted_body' => 'Create a free account to continue generating try-ons',
        'unlimited' => 'Unlimited try-ons',
        'gated' => 'Thanks for signing up — try-ons are limited here',
    ],
    'signup' => [
        'title' => 'Quick sign-up',
        'name' => 'Full name',
        'email' => 'Email',
        'phone' => 'Phone',
        'phone_optional' => 'Phone (optional)',
        'why' => 'We use this to save your try-ons and keep you updated',
        'consent' => 'I agree to the terms and privacy policy',
        'submit' => 'Continue',
        'errors' => [
            'email_taken' => 'This email is already registered',
            'network' => "Couldn't sign you up. Try again.",
        ],
        'success' => "You're all set",
    ],
    'unavailable' => [
        'title' => "Try-on isn't available right now",
        'body' => 'Please check back soon',
    ],
    'errors' => [
        'generic' => 'Something went wrong. Please try again.',
        'network' => 'Check your connection and try again',
    ],
];
