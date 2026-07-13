<?php

namespace App\Http\Shopify\Controllers;

use App\Domain\Shopify\Auth\ShopifyInstaller;
use App\Domain\Shopify\Auth\ShopifyOAuthException;
use App\Models\ShopifyPendingInstall;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * InstallClaimController — the second half of `install_new_shop`: an AUTHENTICATED
 * account consumes the parked install EXACTLY ONCE (docs/shopify/DECISIONS.md §2).
 *
 * The claim token lives in the SESSION, never in a URL: a claim link that could be
 * replayed would let a stranger attach someone else's store to their own account. An
 * unauthenticated visit therefore keeps the token in the session, bounces the merchant
 * to register-or-login, and resumes here afterwards (the intended-URL path).
 *
 * The tenant boundary: the Site + ShopifyConnection are created by ShopifyInstaller
 * INSIDE Tenant::run($accountId) — the pending row itself never carries an account, and
 * it is DELETED on consumption, so a second visit finds nothing to claim.
 */
final class InstallClaimController
{
    // === CONSTANTS ===
    private const CFG_LOGIN_PATH = 'shopify.merchant_login_path';

    private const CFG_PANEL_PATH = 'shopify.merchant_panel_path';

    private const SESSION_CLAIM_TOKEN = OAuthController::SESSION_CLAIM_TOKEN;

    private const SESSION_INTENDED = 'url.intended';

    private const LOG_DENIED = 'shopify.install.claim_denied';

    private const ERROR_CONTENT_TYPE = 'text/plain';

    private const ERROR_BODY_TEMPLATE = "Shopify install failed: %s\n(code: %s)";

    public function __construct(
        private readonly ShopifyInstaller $installer,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $claimToken = (string) $request->session()->get(self::SESSION_CLAIM_TOKEN, '');

        try {
            $pending = ShopifyPendingInstall::findClaimable($claimToken);

            if ($pending === null) {
                // No token, an unknown token, an expired park, or an install already
                // claimed. Fail closed — never fall through to "create something".
                throw ShopifyOAuthException::pendingInstallExpired();
            }

            $user = Auth::user();

            if ($user === null) {
                // Register-or-login, then come straight back here (token stays in session).
                $request->session()->put(self::SESSION_INTENDED, $request->url());

                return redirect()->to((string) config(self::CFG_LOGIN_PATH));
            }

            $accountId = (int) ($user->account_id ?? 0);

            if ($accountId <= 0) {
                throw ShopifyOAuthException::noAccount(); // e.g. a platform super-admin
            }

            $connection = $this->installer->claim($pending, $accountId);

            $request->session()->forget(self::SESSION_CLAIM_TOKEN);

            return redirect()->to($this->panelUrl($accountId, (int) $connection->site_id));
        } catch (ShopifyOAuthException $e) {
            $request->session()->forget(self::SESSION_CLAIM_TOKEN);

            Log::warning(self::LOG_DENIED, ['code' => $e->errorCode, 'shop_domain' => $e->shopDomain]);

            return response(
                sprintf(self::ERROR_BODY_TEMPLATE, $e->getMessage(), $e->errorCode),
                $e->httpStatus(),
                ['Content-Type' => self::ERROR_CONTENT_TYPE],
            );
        }
    }

    /** The merchant panel, on the newly connected shop. */
    private function panelUrl(int $accountId, int $siteId): string
    {
        $slug = Tenant::run($accountId, fn (): ?string => Site::query()->whereKey($siteId)->value('slug'));
        $base = rtrim((string) config(self::CFG_PANEL_PATH), '/');

        return $slug === null ? $base : $base.'/'.$slug;
    }
}
