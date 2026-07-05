<?php

namespace App\Domain\Platform;

use App\Domain\Activity\ActivityRecorder;
use App\Filament\Merchant\Pages\Dashboard;
use App\Models\ActivityEvent;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformShopDrillIn — the audited "Open shop workspace" bridge.
 *
 * A super-admin opens a specific shop's merchant workspace straight from the platform
 * panel. The ACCESS itself is already permitted by User::canAccessTenant (a super-admin
 * may drill into any shop) and bound per-request by BindMerchantAccount; this service adds
 * the two missing pieces the bridge needs:
 *
 *  1. workspaceUrl(): the merchant-panel URL for the shop's tools — the merchant Dashboard
 *     scoped to that Site tenant. Not a scope bypass: it only builds a route.
 *
 *  2. record(): an EXPLICIT, LOGGED drill-in trace (platform_shop_drill_in), so a
 *     super-admin entering another account's shop is auditable. The event is account-scoped
 *     to the TARGET shop's account (Tenant::run stamps account_id via BelongsToAccount).
 *
 * Guarded by PlatformGuard (super-admin only), like every other platform seam. The recorder
 * swallows its own errors, so logging never blocks opening the workspace.
 */
final class PlatformShopDrillIn
{
    // === CONSTANTS ===
    // The merchant panel the workspace URL targets.
    private const MERCHANT_PANEL = 'merchant';

    public function __construct(
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * The merchant-panel URL that lands the operator on the shop's tools (the merchant
     * Dashboard for this Site tenant). Super-admin only.
     */
    public function workspaceUrl(Site $site): string
    {
        PlatformGuard::assert();

        return Dashboard::getUrl(panel: self::MERCHANT_PANEL, tenant: $site);
    }

    /**
     * Record the audited drill-in: WHO (the super-admin) opened WHICH shop, account-scoped
     * to the target shop's own account. Super-admin only. Best-effort (the recorder swallows
     * its own errors) — logging never blocks the bridge.
     */
    public function record(Site $site): void
    {
        PlatformGuard::assert();

        $actor = Auth::user();

        Tenant::run($site->account_id, function () use ($site, $actor): void {
            $this->recorder->record(
                kind: ActivityEvent::KIND_PLATFORM_SHOP_DRILL_IN,
                subject: $site,
                details: [
                    'site_id' => (int) $site->getKey(),
                    'account_id' => (int) $site->account_id,
                    'super_admin_id' => $actor?->getKey(),
                    'super_admin_email' => $actor?->getAttribute('email'),
                ],
                siteId: (int) $site->getKey(),
            );
        });
    }
}
