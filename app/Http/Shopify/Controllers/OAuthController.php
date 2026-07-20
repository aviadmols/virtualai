<?php

namespace App\Http\Shopify\Controllers;

use App\Domain\Shopify\Auth\ShopifyAccountProvisioner;
use App\Domain\Shopify\Auth\ShopifyInstaller;
use App\Domain\Shopify\Auth\ShopifyOAuth;
use App\Domain\Shopify\Auth\ShopifyOAuthException;
use App\Domain\Shopify\Auth\ShopifyOAuthState;
use App\Domain\Shopify\ShopifyCredentials;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * OAuthController — the two install origins (docs/shopify/DECISIONS.md §2).
 *
 *  - start()    connect_existing_site: the merchant clicks Connect in the Vsio panel.
 *               The signed state carries {account_id, site_id}; the callback attaches the
 *               store to THAT site.
 *  - install()  install_new_shop: the merchant arrives from Shopify with no Vsio
 *               account. The state carries only the flow; the callback parks the token.
 *  - callback() shared: verify HMAC + state + shop regex -> exchange the code for an
 *               OFFLINE token -> persist via ShopifyInstaller (the ONE persist path), or
 *               park a pending install and send the merchant to register-or-login.
 *
 * EVERY tampered input (forged hmac, forged/expired/replayed state, a non-myshopify
 * shop, another account's shop) is a TYPED 403 and writes NOTHING. A Shopify-side
 * failure is a 502. Neither is ever a 500, and no token or secret ever reaches a log or
 * a response body.
 */
final class OAuthController
{
    // === CONSTANTS ===
    // Query params on our own entry points.
    private const Q_SHOP = 'shop';

    private const Q_SITE = 'site';

    private const Q_CODE = 'code';

    private const Q_STATE = 'state';

    private const Q_HMAC = 'hmac';

    // Route names (routes/shopify.php owns the paths).
    private const ROUTE_CALLBACK = 'shopify.oauth.callback';

    // Where an unauthenticated "Connect" click, and a finished install, land.
    private const CFG_LOGIN_PATH = 'shopify.merchant_login_path';

    private const CFG_PANEL_PATH = 'shopify.merchant_panel_path';

    // The session key holding the one-time claim token for a parked install.
    public const SESSION_CLAIM_TOKEN = 'shopify.pending_install_claim';

    // Laravel's post-login redirect key.
    private const SESSION_INTENDED = 'url.intended';

    private const LOG_START = 'shopify.oauth.start';

    private const LOG_CALLBACK = 'shopify.oauth.callback';

    private const LOG_DENIED = 'shopify.oauth.denied';

    private const ERROR_CONTENT_TYPE = 'text/plain';

    private const ERROR_BODY_TEMPLATE = "Shopify install failed: %s\n(code: %s)";

    // The embedded app's home inside the Shopify admin, keyed by client_id so a handle
    // rename never breaks it. Shopify 302s this to the admin.shopify.com embedded page.
    private const EMBEDDED_ADMIN_URL_TEMPLATE = 'https://%s/admin/apps/%s';

    // The top-level hand-off page for install_new_shop. A 200 (not a 302) so the session
    // cookie carrying the state nonce is committed BEFORE the cross-site round-trip to
    // Shopify — otherwise the freshly-set partitioned cookie can be dropped and the
    // callback fails the state check on the FIRST attempt (TS-INFRA-005).
    private const VIEW_AUTHORIZE_REDIRECT = 'shopify.oauth.redirect';

    public function __construct(
        private readonly ShopifyOAuth $oauth,
        private readonly ShopifyOAuthState $state,
        private readonly ShopifyInstaller $installer,
        private readonly ShopifyAccountProvisioner $provisioner,
        private readonly ShopifyCredentials $credentials,
    ) {}

    /**
     * connect_existing_site — the merchant is signed in to Vsio and connects a store
     * to an existing site. The site is resolved through the FAIL-CLOSED tenant scope, so
     * another account's site id simply does not exist here (403, never a cross-account
     * connect).
     */
    public function start(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();

        if ($user === null) {
            // Come back here after login (the merchant panel honors the intended URL).
            $request->session()->put(self::SESSION_INTENDED, $request->fullUrl());

            return redirect()->to((string) config(self::CFG_LOGIN_PATH));
        }

        try {
            $accountId = (int) ($user->account_id ?? 0);

            if ($accountId <= 0) {
                throw ShopifyOAuthException::noAccount();
            }

            $shop = ShopifyOAuth::normalizeShopDomain($request->query(self::Q_SHOP));

            if ($shop === null) {
                throw ShopifyOAuthException::invalidShop($request->query(self::Q_SHOP));
            }

            $siteId = (int) $request->query(self::Q_SITE, 0);
            $site = $this->siteOwnedBy($accountId, $siteId);

            $state = $this->state->issue(
                flow: ShopifyOAuthState::FLOW_CONNECT_EXISTING_SITE,
                session: $request->session(),
                accountId: $accountId,
                siteId: (int) $site->getKey(),
            );

            Log::info(self::LOG_START, [
                'flow' => ShopifyOAuthState::FLOW_CONNECT_EXISTING_SITE,
                'shop_domain' => $shop,
                'account_id' => $accountId,
                'site_id' => (int) $site->getKey(),
            ]);

            return redirect()->away($this->oauth->authorizeUrl($shop, $state, route(self::ROUTE_CALLBACK)));
        } catch (ShopifyOAuthException $e) {
            return $this->denied($e);
        }
    }

    /**
     * install_new_shop — the merchant arrives from Shopify (app URL entry) with no Tray
     * On session. When Shopify signs the entry (it does), the hmac MUST verify; the
     * callback is HMAC-verified regardless, so an unsigned entry can only ever produce a
     * redirect to Shopify's own grant screen.
     */
    public function install(Request $request): Response|RedirectResponse
    {
        try {
            $query = $request->query();

            if (isset($query[self::Q_HMAC]) && ! $this->oauth->verifyRequestHmac($query)) {
                throw ShopifyOAuthException::invalidHmac($request->query(self::Q_SHOP));
            }

            $shop = ShopifyOAuth::normalizeShopDomain($request->query(self::Q_SHOP));

            if ($shop === null) {
                throw ShopifyOAuthException::invalidShop($request->query(self::Q_SHOP));
            }

            $state = $this->state->issue(
                flow: ShopifyOAuthState::FLOW_INSTALL_NEW_SHOP,
                session: $request->session(),
            );

            Log::info(self::LOG_START, ['flow' => ShopifyOAuthState::FLOW_INSTALL_NEW_SHOP, 'shop_domain' => $shop]);

            // A top-level 200 hand-off page navigates the browser to Shopify's grant screen.
            // The state nonce lives in the shared cache (not the session), so it survives the
            // cross-site OAuth round-trip regardless of the Partitioned session cookie.
            $authorizeUrl = $this->oauth->authorizeUrl($shop, $state, route(self::ROUTE_CALLBACK));

            return response()->view(self::VIEW_AUTHORIZE_REDIRECT, ['authorizeUrl' => $authorizeUrl]);
        } catch (ShopifyOAuthException $e) {
            return $this->denied($e);
        }
    }

    /** The shared OAuth callback for BOTH flows. Every check fails closed. */
    public function callback(Request $request): Response|RedirectResponse
    {
        $correlationId = CorrelationId::mint();

        try {
            $query = $request->query();

            if (! $this->oauth->isConfigured()) {
                throw ShopifyOAuthException::notConfigured();
            }

            // 1. Shopify's request signature over the whole query (minus hmac).
            if (! $this->oauth->verifyRequestHmac($query)) {
                throw ShopifyOAuthException::invalidHmac($request->query(self::Q_SHOP));
            }

            // 2. The shop must be a canonical *.myshopify.com host — the token exchange
            //    is a POST to this host, so this regex is a security boundary.
            $shop = ShopifyOAuth::normalizeShopDomain($request->query(self::Q_SHOP));

            if ($shop === null) {
                throw ShopifyOAuthException::invalidShop($request->query(self::Q_SHOP));
            }

            // 3. OUR signed, short-lived, SINGLE-USE state (the CSRF wall of the install): the
            //    signature is unforgeable and the nonce is consumed once (shared cache). It is
            //    NOT browser-bound — store-theft on the account-bearing connect flow is refused
            //    by the account re-check below; install_new_shop provisions a fresh HMAC-verified
            //    shop, so there is nothing to steal.
            $payload = $this->state->verify((string) $request->query(self::Q_STATE, ''), $request->session());

            if ($payload === null) {
                throw ShopifyOAuthException::invalidState($shop);
            }

            // 4. STORE-THEFT WALL. A connect names the account the store will be attached to,
            //    so the browser completing it must BE that account. Without this, a phished
            //    store admin approving a genuine grant screen would hand their store — and its
            //    offline token — to whoever minted the state. Fails closed on a guest too.
            if ($payload->isConnectExistingSite()) {
                $callerAccountId = (int) (Auth::user()?->account_id ?? 0);

                if ($payload->accountId === null || $callerAccountId !== $payload->accountId) {
                    throw ShopifyOAuthException::invalidState($shop);
                }
            }

            $code = (string) $request->query(self::Q_CODE, '');

            if ($code === '') {
                throw ShopifyOAuthException::missingCode($shop);
            }

            // 4. Code -> OFFLINE access token (the only place the token is created).
            $token = $this->oauth->exchangeCode($shop, $code);

            Log::info(self::LOG_CALLBACK, ['correlation_id' => $correlationId, 'flow' => $payload->flow, 'shop_domain' => $shop]);

            if ($payload->isConnectExistingSite()) {
                if ($payload->accountId === null || $payload->siteId === null) {
                    throw ShopifyOAuthException::invalidState($shop);
                }

                $connection = $this->installer->connect(
                    accountId: $payload->accountId,
                    siteId: $payload->siteId,
                    shopDomain: $shop,
                    token: $token,
                    flow: ShopifyOAuthState::FLOW_CONNECT_EXISTING_SITE,
                    correlationId: $correlationId,
                );

                return redirect()->to($this->panelUrl($payload->accountId, (int) $connection->site_id));
            }

            // install_new_shop. A shop we ALREADY know re-activates its existing
            // connection in place (never a duplicate, never a second Site). Reinstall from
            // Shopify auto-logs the shop's owner in (same trust anchor as a first install:
            // the HMAC-verified shop), so the embedded app opens straight into the panel.
            $known = $this->installer->reconnectKnownShop($shop, $token, $correlationId);

            if ($known !== null) {
                $this->loginOwner((int) $known->account_id, $request);

                return redirect()->away($this->embeddedAdminUrl($shop));
            }

            // Brand-new shop, and a MERCHANT is ALREADY signed in to Vsio: attach the store
            // directly to that account in THIS verified callback. Never park + redirect through
            // /claim: ambient session availability can differ after the OAuth round-trip, which
            // used to leak the install out of Shopify and onto /merchant/login.
            //
            // A signed-in SUPER-ADMIN owns no account of their own (account_id === null), so they
            // fall THROUGH to the SSO auto-provisioning below — which mints the SHOP's own account
            // — instead of the old no_account dead-end that blocked a super-admin from ever
            // installing/reinstalling a store.
            $sessionAccountId = Auth::check() ? (int) (Auth::user()?->account_id ?? 0) : 0;

            if ($sessionAccountId > 0) {
                $this->installer->installFreshShop($sessionAccountId, $shop, $token, $correlationId);

                return redirect()->away($this->embeddedAdminUrl($shop));
            }

            // Brand-new shop with NO Vsio session: Shopify-SSO auto-provisioning. The shop
            // was HMAC-verified above, so only the real store admin who approved the grant
            // reaches here — we mint the account + owner (opening grant included), create the
            // Site + connection, LOG THE OWNER IN, and land them straight in the merchant
            // panel (no Sign-in wall). The session is regenerated on login (fixation).
            $result = $this->provisioner->provisionForInstall($shop, $token, $correlationId);

            Auth::login($result->owner);
            $request->session()->regenerate();

            // Back INTO the Shopify admin: the embedded app opens the onboarding shell there.
            return redirect()->away($this->embeddedAdminUrl($shop));
        } catch (ShopifyOAuthException $e) {
            return $this->denied($e, $correlationId);
        }
    }

    // === Internals ===

    /** The site, read through the fail-closed tenant scope. A foreign id is a 403. */
    private function siteOwnedBy(int $accountId, int $siteId): Site
    {
        try {
            return Tenant::run($accountId, fn (): Site => Site::query()->findOrFail($siteId));
        } catch (ModelNotFoundException) {
            throw ShopifyOAuthException::siteNotOwned();
        }
    }

    /** Where a finished install lands: the merchant panel, on the connected shop. */
    private function panelUrl(int $accountId, int $siteId): string
    {
        $slug = Tenant::run($accountId, fn (): ?string => Site::query()->whereKey($siteId)->value('slug'));
        $base = rtrim((string) config(self::CFG_PANEL_PATH), '/');

        return $slug === null ? $base : $base.'/'.$slug;
    }

    /** The embedded app's home inside the Shopify admin (client_id-keyed). */
    private function embeddedAdminUrl(string $shop): string
    {
        return sprintf(self::EMBEDDED_ADMIN_URL_TEMPLATE, $shop, $this->credentials->clientId());
    }

    /**
     * Log the account owner in for an install-from-Shopify flow — the canonical owner rule
     * (earliest user of the account). A top-level session cookie is set here (fixation
     * defense), which also lets the embedded shell's "open dashboard" land without a wall.
     * A missing owner (corrupt state) is skipped, never a crash.
     */
    private function loginOwner(int $accountId, Request $request): void
    {
        $owner = User::query()->forAccount($accountId)->orderBy('id')->first();

        if ($owner === null) {
            return;
        }

        Auth::login($owner);
        $request->session()->regenerate();
    }

    /** The typed denial: 403 (tampered) / 409 (conflict) / 502 (not configured), never 500. */
    private function denied(ShopifyOAuthException $e, ?string $correlationId = null): Response
    {
        Log::warning(self::LOG_DENIED, [
            'correlation_id' => $correlationId,
            'code' => $e->errorCode,
            'shop_domain' => $e->shopDomain,
        ]);

        return response(
            sprintf(self::ERROR_BODY_TEMPLATE, $e->getMessage(), $e->errorCode),
            $e->httpStatus(),
            ['Content-Type' => self::ERROR_CONTENT_TYPE],
        );
    }
}
