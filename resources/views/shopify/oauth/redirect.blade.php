<!DOCTYPE html>
{{-- Top-level OAuth hand-off. install() renders this 200 page and navigates the browser to
     Shopify's grant screen. The single-use state nonce lives in the shared server-side cache
     (NOT the session), so it survives the cross-site OAuth round-trip regardless of the
     SameSite=None; Secure; Partitioned session cookie — which a Partitioned cookie does not
     reliably carry across a top-level cross-site redirect (that was the invalid_state cause). --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="0;url={{ $authorizeUrl }}">
    <title>{{ __('shopify_embedded.title') }}</title>
    <style>
        :root { --toe-ink: #1a1a2e; --toe-muted: #6b7280; --toe-accent: #4f46e5; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Assistant", sans-serif;
            color: var(--toe-ink); display: grid; place-items: center; min-block-size: 100vh; padding: 24px;
        }
        .toe-redirect { text-align: center; color: var(--toe-muted); }
        .toe-redirect a { color: var(--toe-accent); font-weight: 600; }
    </style>
</head>
<body>
<p class="toe-redirect">
    {{ __('shopify_embedded.redirect.message') }}
    <br>
    <a href="{{ $authorizeUrl }}" rel="noopener">{{ __('shopify_embedded.redirect.continue') }}</a>
</p>
<script>
    // Navigate the top-level frame to Shopify's grant screen. The session cookie set on this
    // 200 response is committed first, so it returns on the callback and the nonce is found.
    window.location.replace(@json($authorizeUrl));
</script>
</body>
</html>
