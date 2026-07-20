<?php

// === CONSTANTS: the merchant per-shop try-on prompt editor (TryOnPrompt page). ===
return [
    'nav' => 'Try-on prompt',
    'title' => 'Try-on prompt',
    'field' => [
        'label' => 'Your try-on prompt',
        'help' => 'Guide how the try-on image is generated so it stays faithful to your product. Weave in the product\'s own fields with the {{tokens}} below. Leave empty to use the platform default.',
    ],
    'tokens' => [
        'title' => 'Product fields you can insert',
        'sub' => 'Type any of these in your prompt — each is replaced with the product\'s real value when the image is generated.',
    ],
    'save' => 'Save prompt',
    'saved' => 'Prompt saved',
    'no_site' => 'Connect a shop to edit its try-on prompt.',
    'errors' => [
        'save_failed' => 'Could not save the prompt. Please try again.',
    ],
];
