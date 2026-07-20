<!DOCTYPE html>
{{-- Top-level OAuth hand-off. install() renders THIS 200 page (not a 302) so the session
     cookie that carries the single-use, browser-bound state nonce is COMMITTED in the
     browser BEFORE the cross-site round-trip to Shopify. A freshly-set
     SameSite=None; Secure; Partitioned session cookie can be dropped when it is set on a
     redirect response, which left the callback's session without the nonce (invalid_state)
     on the FIRST install attempt (it only worked once the cookie already existed). The
     browser-binding is unchanged — the nonce still lives in this session. --}}
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
