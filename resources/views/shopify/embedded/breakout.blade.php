<!DOCTYPE html>
{{-- The BREAKOUT page: this shop is not installed yet, and we are inside the admin
     iframe. OAuth cannot run here (the signed state is session-bound and third-party
     cookies do not exist), so App Bridge escapes top-level to the install route.
     The App Bridge CDN script is the FIRST and ONLY external script. --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>{{ __('shopify_embedded.title') }}</title>
    <style>
        :root { --toe-ink: #1a1a2e; --toe-muted: #6b7280; --toe-accent: #4f46e5; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Assistant", sans-serif;
            color: var(--toe-ink); display: grid; place-items: center; min-block-size: 100vh; padding: 24px;
        }
        .toe-breakout { text-align: center; color: var(--toe-muted); }
        .toe-breakout a { color: var(--toe-accent); font-weight: 600; }
    </style>
</head>
<body>
<p class="toe-breakout">
    <a href="{{ $installUrl }}" target="_top" rel="noopener">{{ __('shopify_embedded.breakout.continue') }}</a>
</p>
<script>
    // App Bridge patches window.open so '_top' escapes the admin iframe.
    window.open(@json($installUrl), '_top');
</script>
</body>
</html>
