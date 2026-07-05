<?php

// === KEYS: appearance.* — merchant widget-appearance page. Mirror: lang/he/appearance.php ===

return [
    'nav' => 'Widget appearance',
    'title' => 'Widget appearance',
    'save' => 'Save appearance',
    'saved' => 'Appearance saved',
    'empty' => 'Add a site first',
    'empty_sub' => 'Create a site to customise its try-on button and popup.',
    'button' => [
        'title' => 'Button',
        'sub' => 'Where the Tray On button appears on your product page, and how it looks.',
        'placement' => 'Button placement',
        'label' => 'Button text',
        'bg' => 'Button colour',
        'text' => 'Text colour',
    ],
    'popup' => [
        'title' => 'Popup',
        'sub' => 'How the try-on popup looks when a shopper opens it.',
        'theme' => 'Theme',
        'accent' => 'Accent colour',
        'ask_height' => 'Ask for the shopper’s height',
        'ask_height_help' => 'Turn off for jewelry, furniture or anything where height is irrelevant. On for clothing and footwear.',
    ],
    'placement' => [
        'section' => 'Button placement',
        'section_sub' => 'Load a preview of your product page and click exactly where the Tray On button should go.',
        'after_add_to_cart' => 'After “Add to cart”',
        'before_add_to_cart' => 'Before “Add to cart”',
        'fixed_bottom_right' => 'Fixed — bottom right',
        'fixed_bottom_left' => 'Fixed — bottom left',
        'custom' => 'Custom — picked visually',
    ],
    'visual' => [
        'pick' => 'Pick visually',
        'eyebrow' => 'Placement',
        'modal_title' => 'Place your button',
        'url_placeholder' => 'https://your-store.com/product/…',
        'load' => 'Load preview',
        'loading' => 'Loading…',
        'load_hint' => 'Enter a product page URL and load a preview to place the button.',
        'preview' => 'Product page preview',
        'hint' => 'Click any element in the preview to place the button next to it.',
        'position_label' => 'Position',
        'position' => [
            'before' => 'Before',
            'after' => 'After',
            'prepend' => 'Inside — start',
            'append' => 'Inside — end',
        ],
        'corner_label' => 'Or a floating corner',
        'corner' => [
            'br' => 'Bottom right',
            'bl' => 'Bottom left',
        ],
        'cancel' => 'Cancel',
        'confirm' => 'Use this spot',
        'applied' => 'Placement updated — remember to save.',
        'summary_custom' => 'Button appears :position :anchor',
        'verdict' => [
            'unique' => 'Great — this matches exactly one element.',
            'none' => 'This selector matches nothing on the page. Pick another element.',
            'multiple' => 'This matches :count elements — the button will use the first. Try a more specific element.',
            'expired' => 'The preview expired. Load it again.',
        ],
        'errors' => [
            'bad_url' => 'Enter a valid product page URL.',
            'rate_limited' => 'Too many previews just now. Wait a moment and try again.',
            'load_failed' => 'Could not load a preview of that page. Check the URL and try again.',
            'no_pick' => 'Click an element in the preview first.',
        ],
    ],
    'theme' => [
        'light' => 'Light',
        'dark' => 'Dark',
    ],
    'errors' => [
        'save_failed' => 'Could not save the appearance. Check the values and try again.',
    ],
];
