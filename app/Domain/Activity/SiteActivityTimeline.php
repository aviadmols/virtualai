<?php

namespace App\Domain\Activity;

use App\Models\ActivityEvent;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * SiteActivityTimeline — the read-side of a SHOP's recent activity for the merchant
 * Overview hub (WS1). Returns the latest few immutable EndUserActivityItem DTOs
 * (newest first) of everything that happened on one shop: try-ons, leads, key
 * rotations, settings changes.
 *
 * SCOPE — one site. activity_events carries a nullable site_id; an event belongs to
 * this shop when its site_id equals the shop's id. Account-level events with no
 * site_id (e.g. account-wide credit grants) are NOT surfaced here — the hub is
 * about one shop. This is the site-level companion to EndUserActivityTimeline
 * (which is scoped to one lead).
 *
 * Tenant-safety: the read runs inside Tenant::run($site->account_id), so the
 * ActivityEvent query passes through the BelongsToAccount global scope AND is
 * narrowed to this shop by an explicit site_id filter. No withoutGlobalScopes(); a
 * forgotten filter fails closed. Account B can never see account A's shop activity.
 */
final class SiteActivityTimeline
{
    // === CONSTANTS ===
    // How many recent events the hub strip shows (a compact "what's happening" list).
    private const DEFAULT_LIMIT = 6;

    // Detail keys the recorder curates that make a short human-readable line, in
    // priority order (the first present, non-secret scalar wins). All are non-secret.
    private const DETAIL_KEYS = ['to', 'failure_code', 'reason', 'product_id'];

    /**
     * The shop's recent activity, newest first (default the latest handful).
     * Account-scoped to the shop's own account and narrowed to this shop.
     *
     * @return Collection<int,EndUserActivityItem>
     */
    public function forSite(Site $site, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $siteId = (int) $site->getKey();
        $limit = max(1, $limit);

        return Tenant::run((int) $site->account_id, function () use ($siteId, $limit): Collection {
            return ActivityEvent::query()
                ->where('site_id', $siteId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (ActivityEvent $event): EndUserActivityItem => $this->toItem($event));
        });
    }

    /** Map one activity event to the immutable timeline row. */
    private function toItem(ActivityEvent $event): EndUserActivityItem
    {
        return new EndUserActivityItem(
            id: (int) $event->getKey(),
            kind: (string) $event->kind,
            labelKey: EndUserActivityItem::LABEL_PREFIX.$event->kind,
            actor: (string) $event->actor,
            detail: $this->detailLine($event),
            createdAt: $event->created_at?->toIso8601String(),
        );
    }

    /**
     * A short, non-secret human-readable detail from the recorder-curated bag: the
     * first present scalar among a small allow-list of keys (never a raw payload,
     * never a secret). Null when none apply.
     */
    private function detailLine(ActivityEvent $event): ?string
    {
        $details = $event->details ?? [];

        if (! is_array($details) || $details === []) {
            return null;
        }

        foreach (self::DETAIL_KEYS as $key) {
            $value = $details[$key] ?? null;
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
