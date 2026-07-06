<?php

/*
 * Banners module strings (merchant panel). `he` mirrors every key 1:1 (release-blocker parity).
 */

return [

    'nav' => 'Banners',
    'singular' => 'Banner',
    'plural' => 'Banners',
    'title' => 'Banners',
    'new' => 'New banner',
    'saved' => 'Banner saved.',
    'empty' => 'No banners yet',
    'empty_sub' => 'Create a banner, generate its image from a brief, then choose where it shows.',

    'col' => [
        'artwork' => 'Preview',
        'name' => 'Name',
        'status' => 'Status',
        'composition' => 'Type',
        'clicks' => 'Clicks',
        'impressions' => 'Views',
        'ctr' => 'CTR',
        'updated' => 'Updated',
    ],

    'status_option' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'paused' => 'Paused',
        'archived' => 'Archived',
    ],

    'composition_option' => [
        'image' => 'Full image',
        'overlay' => 'Image + text',
    ],

    'field' => [
        'name' => 'Banner name',
        'name_help' => 'Only you see this — a label to find the banner later.',
        'composition' => 'Banner type',
        'composition_help' => 'Full image: the whole banner is the generated picture. Image + text: your headline and button are drawn crisply over the picture.',
        'target_url' => 'Click destination (URL)',
        'target_url_help' => 'Where a shopper goes when they click the banner. Leave empty for a non-clickable banner.',
        'alt_text' => 'Alt text',
        'alt_text_help' => 'A short description of the image for accessibility and SEO.',
        'artwork' => 'Chosen image',
        'artwork_help' => 'Pick one of the generated candidates below as the banner image.',
    ],

    'overlay' => [
        'section' => 'Text over the image',
        'section_help' => 'Shown only for the "Image + text" type. Rendered as crisp HTML text over the generated picture.',
        'headline' => 'Headline',
        'subtext' => 'Subtext',
        'cta_label' => 'Button label',
    ],

    'generate' => [
        'action' => 'Generate image',
        'heading' => 'Describe the banner',
        'brief' => 'Brief',
        'brief_help' => 'Describe the banner you want — theme, mood, colors, product. Each generate makes a new candidate.',
        'reference' => 'Reference image (optional)',
        'reference_help' => 'Attach a product or brand image to guide the result.',
        'submit' => 'Generate',
        'queued' => 'Generating your banner — it will appear in the candidates shortly.',
        'failed' => 'Could not start the generation. Please try again.',
    ],

    'candidates' => [
        'section' => 'Generated candidates',
        'section_help' => 'Each generation costs credits. Choose one as the banner image.',
        'none' => 'No candidates yet — generate one above.',
        'select' => 'Use this image',
        'status' => [
            'pending' => 'Queued…',
            'processing' => 'Generating…',
            'succeeded' => 'Ready',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
        ],
    ],

    'action' => [
        'activate' => 'Activate',
        'pause' => 'Pause',
        'archive' => 'Archive',
    ],

    'errors' => [
        'save_failed' => 'Could not save the banner. Please try again.',
        'invalid_name' => 'Enter a banner name (up to 120 characters).',
        'invalid_composition' => 'Choose a valid banner type.',
        'invalid_target_url' => 'Enter a valid http(s) URL, or leave it empty.',
        'invalid_overlay' => 'Check the overlay text — it is too long or has an unexpected field.',
        'invalid_alt_text' => 'The alt text is too long (up to 240 characters).',
        'no_artwork' => 'Choose a generated image before activating the banner.',
        'asset_not_selectable' => 'That candidate is not a finished image for this banner.',
    ],
];
