<!DOCTYPE html>
{{-- === TOKENS: the embedded-admin shell. Self-contained (no Vite): --toe-* custom
     properties below are the ONLY styling source; component classes consume them.
     The App Bridge CDN script is the FIRST and ONLY external script (Shopify rule). --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>{{ __('shopify_embedded.title') }}</title>
    <style>
        :root {
            --toe-ink: #1a1a2e;
            --toe-muted: #6b7280;
            --toe-surface: #ffffff;
            --toe-canvas: #f6f6f7;
            --toe-border: #e5e7eb;
            --toe-accent: #4f46e5;
            --toe-accent-ink: #ffffff;
            --toe-ok: #059669;
            --toe-warn: #d97706;
            --toe-danger: #e11d48;
            --toe-radius: 10px;
            --toe-gap: 16px;
            --toe-font: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Assistant", sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--toe-font);
            background: var(--toe-canvas);
            color: var(--toe-ink);
            line-height: 1.5;
            padding: var(--toe-gap);
        }
        .toe-shell { max-inline-size: 640px; margin-inline: auto; display: grid; gap: var(--toe-gap); }
        .toe-card {
            background: var(--toe-surface);
            border: 1px solid var(--toe-border);
            border-radius: var(--toe-radius);
            padding: 20px;
        }
        .toe-h1 { font-size: 22px; font-weight: 700; }
        .toe-sub { color: var(--toe-muted); margin-block-start: 4px; }
        .toe-h2 { font-size: 15px; font-weight: 600; margin-block-end: 12px; }
        .toe-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding-block: 8px; border-block-start: 1px solid var(--toe-border); }
        .toe-row:first-of-type { border-block-start: 0; }
        .toe-label { color: var(--toe-muted); font-size: 13px; }
        .toe-value { font-size: 14px; font-weight: 500; overflow-wrap: anywhere; text-align: end; }
        .toe-key { font-family: ui-monospace, monospace; font-size: 12px; }
        .toe-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .toe-pill--ok { background: #ecfdf5; color: var(--toe-ok); }
        .toe-pill--warn { background: #fffbeb; color: var(--toe-warn); }
        .toe-copy {
            border: 1px solid var(--toe-border); background: var(--toe-surface); color: var(--toe-ink);
            border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer;
        }
        .toe-check { display: flex; align-items: center; gap: 10px; padding-block: 10px; border-block-start: 1px solid var(--toe-border); }
        .toe-check:first-of-type { border-block-start: 0; }
        .toe-check__dot {
            inline-size: 22px; block-size: 22px; border-radius: 999px; flex: none;
            display: grid; place-items: center; font-size: 13px; font-weight: 700;
            border: 2px solid var(--toe-border); color: var(--toe-muted);
        }
        .toe-check--done .toe-check__dot { background: var(--toe-ok); border-color: var(--toe-ok); color: #fff; }
        .toe-check__text { flex: 1; font-size: 14px; }
        .toe-check--done .toe-check__text { color: var(--toe-muted); text-decoration: line-through; }
        .toe-check__action { font-size: 13px; font-weight: 600; color: var(--toe-accent); text-decoration: none; white-space: nowrap; }
        .toe-cta {
            display: block; inline-size: 100%; text-align: center; text-decoration: none;
            background: var(--toe-accent); color: var(--toe-accent-ink);
            border: 0; border-radius: var(--toe-radius); padding: 12px 16px;
            font-size: 15px; font-weight: 600; cursor: pointer;
        }
        .toe-state { text-align: center; color: var(--toe-muted); padding-block: 48px; }
        .toe-state--error { color: var(--toe-danger); }
        .toe-state a { color: var(--toe-accent); }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<div class="toe-shell">
    <div id="toe-loading" class="toe-state">{{ __('shopify_embedded.loading') }}</div>

    <div id="toe-error" class="toe-state toe-state--error" hidden>
        <p id="toe-error-message">{{ __('shopify_embedded.errors.load_failed') }}</p>
    </div>

    <div id="toe-app" hidden>
        <div class="toe-card">
            <h1 class="toe-h1">{{ __('shopify_embedded.welcome.heading') }}</h1>
            <p class="toe-sub">{{ __('shopify_embedded.welcome.sub') }}</p>
        </div>

        <div class="toe-card">
            <h2 class="toe-h2">{{ __('shopify_embedded.details.heading') }}</h2>
            <div class="toe-row">
                <span class="toe-label">{{ __('shopify_embedded.details.shop') }}</span>
                <span class="toe-value" id="toe-shop"></span>
            </div>
            <div class="toe-row">
                <span class="toe-label">{{ __('shopify_embedded.details.email') }}</span>
                <span class="toe-value" id="toe-email"></span>
            </div>
            <div class="toe-row">
                <span class="toe-label">{{ __('shopify_embedded.details.site_key') }}</span>
                <span class="toe-value">
                    <span class="toe-key" id="toe-site-key"></span>
                    <button type="button" class="toe-copy" id="toe-copy"
                            data-copied="{{ __('shopify_embedded.details.copied') }}">{{ __('shopify_embedded.details.copy') }}</button>
                </span>
            </div>
            <div class="toe-row">
                <span class="toe-label">{{ __('shopify_embedded.details.status') }}</span>
                <span class="toe-value">
                    <span class="toe-pill" id="toe-status"
                          data-installed="{{ __('shopify_embedded.details.status_installed') }}"
                          data-uninstalled="{{ __('shopify_embedded.details.status_uninstalled') }}"></span>
                </span>
            </div>
        </div>

        <div class="toe-card">
            <h2 class="toe-h2">{{ __('shopify_embedded.checklist.heading') }}</h2>
            <div class="toe-check" id="toe-check-embed"
                 data-todo="{{ __('shopify_embedded.checklist.embed') }}"
                 data-done="{{ __('shopify_embedded.checklist.embed_done') }}">
                <span class="toe-check__dot">✓</span>
                <span class="toe-check__text"></span>
                <a class="toe-check__action" id="toe-embed-action" href="#" target="_top">{{ __('shopify_embedded.checklist.embed_action') }}</a>
            </div>
            <div class="toe-check" id="toe-check-products"
                 data-todo="{{ __('shopify_embedded.checklist.products') }}"
                 data-done="{{ __('shopify_embedded.checklist.products_done') }}">
                <span class="toe-check__dot">✓</span>
                <span class="toe-check__text"></span>
            </div>
            <div class="toe-check" id="toe-check-tryon"
                 data-todo="{{ __('shopify_embedded.checklist.tryon') }}"
                 data-done="{{ __('shopify_embedded.checklist.tryon_done') }}">
                <span class="toe-check__dot">✓</span>
                <span class="toe-check__text"></span>
            </div>
        </div>

        <a class="toe-cta" id="toe-dashboard" href="#" target="_self">{{ __('shopify_embedded.dashboard_cta') }}</a>
    </div>
</div>

<script>
    // === CONSTANTS === (endpoints are server-rendered; nothing else is configurable here)
    const SESSION_URL = @json($sessionUrl);
    const BOOTSTRAP_URL = @json($bootstrapUrl);

    const el = (id) => document.getElementById(id);

    async function authedFetch(url, options = {}) {
        const token = await window.shopify.idToken();
        return fetch(url, {
            ...options,
            credentials: 'include',
            headers: { ...(options.headers || {}), Authorization: 'Bearer ' + token, Accept: 'application/json' },
        });
    }

    function fail() {
        el('toe-loading').hidden = true;
        el('toe-app').hidden = true;
        el('toe-error').hidden = false;
    }

    function renderCheck(id, done) {
        const item = el(id);
        item.classList.toggle('toe-check--done', done === true);
        item.querySelector('.toe-check__text').textContent =
            done === true ? item.dataset.done : item.dataset.todo;
        const action = item.querySelector('.toe-check__action');
        if (action) action.hidden = done === true;
    }

    function render(data) {
        el('toe-shop').textContent = data.shop.domain;
        el('toe-email').textContent = data.owner.email;
        el('toe-site-key').textContent = data.site.site_key;

        const status = el('toe-status');
        const installed = data.connection.status === 'installed';
        status.textContent = installed ? status.dataset.installed : status.dataset.uninstalled;
        status.classList.add(installed ? 'toe-pill--ok' : 'toe-pill--warn');

        renderCheck('toe-check-embed', data.checklist.embed_enabled);
        renderCheck('toe-check-products', data.checklist.products_imported);
        renderCheck('toe-check-tryon', data.checklist.first_generation);

        el('toe-embed-action').href = data.links.theme_editor;
        el('toe-dashboard').href = data.links.dashboard;

        el('toe-copy').addEventListener('click', async (e) => {
            try {
                await navigator.clipboard.writeText(data.site.site_key);
                e.target.textContent = e.target.dataset.copied;
            } catch { /* clipboard unavailable — the key is selectable text */ }
        });

        el('toe-loading').hidden = true;
        el('toe-app').hidden = false;
    }

    (async function boot() {
        try {
            // JWT -> partitioned session cookie (the bridge into the Filament panel), then go
            // STRAIGHT to the dashboard. The store details + setup checklist that used to live
            // on this welcome screen now live on the Overview inside the panel.
            const session = await authedFetch(SESSION_URL, { method: 'POST' });
            if (!session.ok) return fail();
            const sessionBody = await session.json();
            if (!sessionBody.ok || !sessionBody.dashboard_url) return fail();

            window.location.replace(sessionBody.dashboard_url);
        } catch {
            fail();
        }
    })();
</script>
</body>
</html>
