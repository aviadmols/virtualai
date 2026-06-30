<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Sites\WidgetAppearance;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\Resources\ProductPayload;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetGateService;
use App\Http\Widget\WidgetResponse;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BootstrapController — GET /widget/v1/bootstrap. The public config the widget needs to
 * render itself on a PDP: the confirmed product + variants for this page (if scanned),
 * the per-site selectors, the free-tries policy + the CURRENT lead state for the
 * anon_token, the gallery settings, the consent/privacy copy config, and the locale/RTL.
 *
 * NO secrets: never the widget_secret, never the OpenRouter key, never another site's
 * product. The site + tenant are already resolved + bound by the widget-auth middleware;
 * the account is the site's account. If the PDP isn't scanned/confirmed, returns a clean
 * empty-state shape (product: null) — never a 404 that breaks the widget boot.
 */
final class BootstrapController
{
    // === CONSTANTS ===
    private const QUERY_URL = 'url';
    private const QUERY_ANON_TOKEN = 'anon_token';

    // Throttle the "widget last seen" heartbeat so a busy PDP doesn't write per view.
    private const SEEN_THROTTLE_MINUTES = 5;

    public function __construct(
        private readonly EndUserResolver $endUsers,
        private readonly WidgetGateService $gates,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;

        // Heartbeat: the widget just booted on the store, so the install snippet is live.
        // Lets the setup checklist auto-detect installation (throttled, best-effort).
        $this->touchWidgetSeen($site);

        $product = $this->confirmedProductFor($request, (int) $site->getKey());
        $endUser = $this->resolveEndUser($request, $site);

        $lead = $endUser !== null ? $this->gates->leadState($site, $endUser) : null;

        return WidgetResponse::ok([
            'site' => [
                'locale' => $site->account?->locale ?? 'en',
                'selectors' => $site->selectors ?? [],
                'gallery' => $site->gallery_settings ?? [],
                'privacy' => $site->privacy_config ?? [],
                'free_generations_before_signup' => $site->free_generations_before_signup,
                // Resolved with defaults so the widget always gets a complete, valid look.
                'appearance' => WidgetAppearance::resolve($site->widget_appearance),
            ],
            'lead' => [
                'registered' => $endUser?->isRegistered() ?? false,
                'free_remaining' => $lead?->freeRemaining ?? $site->free_generations_before_signup,
                'signup_required' => $lead?->signupRequired ?? false,
            ],
            // A confirmed product for this PDP, or null (clean empty state, never a 404).
            'product' => $product !== null
                ? ProductPayload::make($product, $product->variants)
                : null,
        ]);
    }

    /**
     * Stamp widget_last_seen_at for the site (the install-snippet heartbeat). Throttled so
     * a busy PDP doesn't write on every page view; a direct row update (no model events,
     * no tenant scope) and best-effort — a write failure must never break the widget boot.
     */
    private function touchWidgetSeen(Site $site): void
    {
        $last = $site->widget_last_seen_at;

        if ($last !== null && $last->gt(now()->subMinutes(self::SEEN_THROTTLE_MINUTES))) {
            return;
        }

        try {
            DB::table('sites')->where('id', $site->getKey())->update(['widget_last_seen_at' => now()]);
        } catch (\Throwable) {
            // best-effort heartbeat — never block a widget boot on it
        }
    }

    /** Match the CONFIRMED product for this PDP url within the bound site (account-scoped). */
    private function confirmedProductFor(Request $request, int $siteId): ?Product
    {
        $url = (string) $request->query(self::QUERY_URL, '');

        if ($url === '') {
            return null;
        }

        return Product::query()
            ->where('site_id', $siteId)
            ->where('source_url_hash', sha1($url))
            ->where('status', Product::STATUS_CONFIRMED)
            ->with('variants')
            ->first();
    }

    /** Resolve (create) the end user from the anon_token, if the widget sent one. */
    private function resolveEndUser(Request $request, \App\Models\Site $site): ?\App\Models\EndUser
    {
        $anonToken = (string) $request->query(self::QUERY_ANON_TOKEN, '');

        if (strlen($anonToken) < 8) {
            return null;
        }

        return $this->endUsers->resolve($site, $anonToken);
    }
}
