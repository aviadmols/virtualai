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

    // The card-grid face (list page).
    'card' => [
        'window' => 'Last 30 days',
        'clicks' => ':count clicks',
        'ctr' => ':value CTR',
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
        'style' => 'Style (optional)',
        'style_help' => 'Pick a curated look. Your brief still guides the content.',
        'brief' => 'Brief',
        'brief_help' => 'Describe the banner you want — theme, mood, colors, product. Each generate makes a new candidate.',
        // The @-mention product picker on the brief.
        'mention_help' => 'Type @ to tag a product — the banner is built from its image and details.',
        'mention_products' => 'Products',
        'mention_based_on' => 'Based on:',
        'mention_empty' => 'Import products first to tag them here.',
        'mention_remove' => 'Remove product',
        'reference' => 'Reference image (optional)',
        'reference_help' => 'Attach a product or brand image to guide the result.',
        'submit' => 'Generate',
        'queued' => 'Generating your banner — it will appear in the candidates shortly.',
        'failed' => 'Could not start the generation. Please try again.',
    ],

    'candidates' => [
        'section' => 'Generated candidates',
        'section_help' => 'Each generation costs credits. Choose one as the banner image.',
        'none' => 'No candidates yet — click "Generate image" above.',
        'select' => 'Use this image',
        'in_use' => 'In use',
        'retry' => 'Try again',
        'selected' => 'Image selected.',
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
        'invalid_placements' => 'Check the placements — a spot is invalid or there are too many.',
    ],

    // --- Visual placement picker (Phase 3) ---
    'placements' => [
        'action' => 'Placements',
        'title' => 'Banner placements',
        'section' => 'Where the banner shows',
        'section_help' => 'Mark the spots on your store where this banner is injected. Pick them visually — up to 8 spots.',
        'pick' => 'Pick on my store',
        'picked_label' => 'Placement spots',
        'count' => '{0}No spots picked|{1}:count spot|[2,*]:count spots',
        'empty' => 'No spots picked yet.',
        'none_yet' => 'Nothing picked yet — click an element in the preview.',
        'remove' => 'Remove this spot',
        'modal_title' => 'Pick where the banner shows',
        'from_scan' => 'Previewing your scanned product',
        'previewing' => 'Previewing',
        'close' => 'Close',
        'done' => 'Done',
        'preview' => 'Store preview',
        'url_placeholder' => 'Paste a page URL from your store to preview',
        'load' => 'Load preview',
        'loading' => 'Loading…',
        'load_hint' => 'Paste a URL from your store and load a live preview, then click where the banner should show.',
        'hint' => 'Click each spot where the banner should appear.',
        'no_banner' => 'Banner not found',
        'no_banner_sub' => 'Open a banner from the Banners list to choose its placements.',
        'position_option' => [
            'before' => 'Before this element',
            'after' => 'After this element',
            'prepend' => 'Inside, at the top',
            'append' => 'Inside, at the bottom',
        ],
        'verdict' => [
            'added' => 'Added — the banner will show at this spot.',
            'duplicate' => 'You already picked this element.',
            'full' => 'You already have the maximum of :max spots.',
            'multiple' => 'That selector matches :count elements. Pick a single, specific spot.',
            'none' => 'That selector matched nothing on the page. Try another element.',
        ],
        'errors' => [
            'load_failed' => 'Could not load a preview of that page. Try another URL.',
            'bad_url' => 'That does not look like a valid URL.',
            'rate_limited' => 'Too many preview attempts. Please wait a minute and try again.',
        ],
    ],

    // --- Display rules / targeting (Phase 4) ---
    'rules' => [
        'section' => 'Who & when it shows',
        'section_help' => 'Target this banner — to whom, on which pages, during which dates, and how often.',
        'audience' => 'Audience',
        'audience_help' => 'Show the banner only to this group of shoppers.',
        'audience_option' => [
            'any' => 'Everyone',
            'club_members' => 'Club members',
            'non_members' => 'Non-members',
            'registered' => 'Registered leads',
            'new_visitors' => 'New visitors',
            'returning_visitors' => 'Returning visitors',
        ],
        'pages_context' => 'Pages',
        'page_option' => [
            'any' => 'Any page',
            'pdp' => 'Product pages',
            'catalog' => 'Catalog / collection',
            'cart' => 'Cart',
        ],
        'url_contains' => 'URL contains',
        'url_contains_help' => 'Only show on pages whose address contains this text (optional).',
        'starts_at' => 'Start (optional)',
        'ends_at' => 'End (optional)',
        'max_per_session' => 'Max views per visit',
        'max_per_session_help' => '0 = unlimited. Otherwise, show the banner at most this many times per shopper session.',
        'locales' => 'Languages',
        'locales_help' => 'Show only for these store languages. Leave empty for all.',
        'locale' => [
            'en' => 'English',
            'he' => 'Hebrew',
        ],
    ],
];
