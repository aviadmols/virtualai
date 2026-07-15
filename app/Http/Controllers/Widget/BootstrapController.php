<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Banners\BannerRules;
use App\Domain\Media\MediaStorage;
use App\Domain\Sites\ClubConfig;
use App\Domain\Sites\WidgetAppearance;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\Resources\ProductPayload;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetGateService;
use App\Http\Widget\WidgetResponse;
use App\Models\Banner;
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

    // Cap how many active banners a bootstrap ships (a store needs a few, not many) — protects
    // the widget payload + main thread; the widget still evaluates each banner's rules client-side.
    private const MAX_BANNERS = 10;

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
                // Height + consent checkboxes default OFF; merchants opt in from appearance.
                'appearance' => WidgetAppearance::resolve($site->widget_appearance, $site->product_category),
            ],
            'lead' => [
                'registered' => $endUser?->isRegistered() ?? false,
                'free_remaining' => $lead?->freeRemaining ?? $site->free_generations_before_signup,
                'signup_required' => $lead?->signupRequired ?? false,
            ],
            // Customer-Club: the resolved per-site config + THIS shopper's membership
            // state (verified_at on the already-resolved end user, read-only — never a
            // new lead just to read). A non-member / no-token shopper reports verified:false.
            'club' => $this->clubPayload($site, $endUser),
            // Merchant banners: the site's ACTIVE, in-schedule, artwork-ready banners. Schedule is
            // enforced here (server); audience/pages/frequency/locale are evaluated by the widget.
            'banners' => $this->bannersPayload($site),
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

    /**
     * The club block the widget acts on: the resolved per-site config (enabled,
     * discount_percent, price_zones) plus THIS shopper's membership state. Member
     * state is read off the already-resolved end user (verified_at) — read-only, no
     * extra query, never a new lead just to check membership.
     *
     * @return array<string,mixed>
     */
    private function clubPayload(Site $site, ?\App\Models\EndUser $endUser): array
    {
        return $this->resolveClubBlock($site, $endUser);
    }

    /**
     * The site's ACTIVE + in-schedule + artwork-ready banners for the widget to render. Each
     * carries its PUBLIC image URL, the picked placements, and the audience/pages/frequency/locale
     * rules the widget evaluates client-side (schedule is already enforced here). Site-scoped by
     * site_id on top of the bound account — never another shop's banners. Capped at MAX_BANNERS.
     *
     * @return array<int,array<string,mixed>>
     */
    private function bannersPayload(Site $site): array
    {
        $media = app(MediaStorage::class);

        return Banner::query()
            ->where('site_id', $site->getKey())
            ->active()
            ->latest('updated_at')
            ->limit(self::MAX_BANNERS * 3) // over-fetch a little; the schedule/artwork filter trims
            ->get()
            ->filter(static fn (Banner $b): bool => $b->hasArtwork() && $b->withinSchedule())
            ->take(self::MAX_BANNERS)
            ->map(static function (Banner $b) use ($media): array {
                $rules = BannerRules::resolve($b->rules);

                return [
                    'id' => (int) $b->getKey(),
                    'composition' => $b->composition,
                    'image_url' => $media->publicUrl($b->image_path),
                    'width' => $b->image_width,
                    'height' => $b->image_height,
                    'target_url' => $b->target_url,
                    'alt' => $b->alt_text,
                    'overlay' => $b->overlay ?? [],
                    'placements' => $b->placements ?? [],
                    // Schedule is server-enforced (above); ship only the client-evaluated rules.
                    'rules' => [
                        BannerRules::KEY_AUDIENCE => $rules[BannerRules::KEY_AUDIENCE],
                        BannerRules::KEY_PAGES => $rules[BannerRules::KEY_PAGES],
                        BannerRules::KEY_FREQUENCY => $rules[BannerRules::KEY_FREQUENCY],
                        BannerRules::KEY_LOCALES => $rules[BannerRules::KEY_LOCALES],
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveClubBlock(Site $site, ?\App\Models\EndUser $endUser): array
    {
        $config = ClubConfig::resolve($site->club_config);

        return [
            'enabled' => $config[ClubConfig::KEY_ENABLED],
            'discount_percent' => $config[ClubConfig::KEY_DISCOUNT_PERCENT],
            'price_zones' => $config[ClubConfig::KEY_PRICE_ZONES],
            // Banner behavior/timing (display-only): when + where the join banner appears and
            // how long a dismissal is remembered. The widget reads these off the same block.
            'banner_trigger' => $config[ClubConfig::KEY_BANNER_TRIGGER],
            'banner_delay_seconds' => $config[ClubConfig::KEY_BANNER_DELAY_SECONDS],
            'banner_scroll_percent' => $config[ClubConfig::KEY_BANNER_SCROLL_PERCENT],
            'banner_position' => $config[ClubConfig::KEY_BANNER_POSITION],
            'banner_dismiss_days' => $config[ClubConfig::KEY_BANNER_DISMISS_DAYS],
            'member' => [
                'verified' => $endUser?->isClubMember() ?? false,
            ],
        ];
    }
}
