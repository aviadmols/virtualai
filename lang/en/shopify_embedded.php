<?php

// === KEYS: shopify_embedded.* — the embedded Shopify-admin surface (App Bridge shell,
// session-token auth, onboarding checklist). Mirror: lang/he/shopify_embedded.php (1:1). ===

return [

    'title' => 'Vsio',

    'auth' => [
        'missing_token' => 'The request carried no session token.',
        'invalid_token' => 'The session token could not be verified.',
        'unknown_shop' => 'This store is not connected to Vsio.',
        'no_owner' => 'No Vsio account owner exists for this store.',
    ],

    'loading' => 'Loading your Vsio account…',

    'welcome' => [
        'heading' => 'Welcome to Vsio',
        'sub' => 'Your account was created automatically from your store — you\'re ready to go.',
    ],

    'details' => [
        'heading' => 'Your account',
        'shop' => 'Store',
        'email' => 'Owner email',
        'site_key' => 'Site key',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'status' => 'Connection',
        'status_installed' => 'Connected',
        'status_uninstalled' => 'Disconnected',
    ],

    'checklist' => [
        'heading' => 'Get set up',
        'embed' => 'Enable the Vsio try-on button in your theme',
        'embed_done' => 'Try-on button enabled in your theme',
        'embed_action' => 'Open theme editor',
        'products' => 'Import your products',
        'products_done' => 'Products imported',
        'tryon' => 'Run your first try-on',
        'tryon_done' => 'First try-on generated',
    ],

    'dashboard_cta' => 'Open the full dashboard',

    'breakout' => [
        'continue' => 'Continue to install Vsio',
    ],

    'redirect' => [
        'message' => 'Taking you to Shopify to approve Vsio…',
        'continue' => 'Continue to Shopify',
    ],

    'errors' => [
        'load_failed' => 'We couldn\'t load your account. Please reload the page.',
        'session_failed' => 'We couldn\'t sign you in inside Shopify. Open Vsio in a new tab instead.',
        'open_new_tab' => 'Open in a new tab',
    ],

];
