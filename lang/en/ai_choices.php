<?php

/*
 * KEYS: ai_choices.* — shared merchant-facing labels for the per-generation AI choices (aspect
 * ratio, image quality). Reused across Image Studio, Try-On and Banners. `he` mirrors 1:1.
 */

return [

    'aspect' => [
        'default' => 'Default for this style',
        'square' => 'Square (1:1)',
        'portrait' => 'Portrait (4:5)',
        'portrait_tall' => 'Tall portrait (2:3)',
        'story' => 'Story (9:16)',
        'landscape' => 'Landscape (3:2)',
        'wide' => 'Wide (16:9)',
    ],

    'quality' => [
        'default' => 'Default for this style',
        'standard' => 'Standard',
        'high' => 'High',
    ],
];
