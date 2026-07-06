<?php

namespace App\Domain\Activity;

use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * EndUserActivityTimeline — the read-side of one lead's activity timeline for the
 * merchant lead card (WS3). Returns immutable EndUserActivityItem DTOs, newest
 * first, of everything a registered shopper did on the shop.
 *
 * SCOPE — what links to an end user. activity_events has NO end_user_id column; the
 * subject is polymorphic (subject_type, subject_id). So an event belongs to this
 * lead when either:
 *   (a) its subject IS the EndUser (lead_registered, lead_added_to_cart), or
 *   (b) its subject is one of THIS lead's Generations (the generation lifecycle:
 *       requested/reserved/processing/succeeded/failed/cancelled + status_changed),
 *       correlated via generations.end_user_id.
 * Account-level (credit) and site-level events are NOT surfaced here — they are not
 * about a single shopper.
 *
 * Tenant-safety: every read runs inside Tenant::run($endUser->account_id), so both
 * the ActivityEvent query AND the generation-id lookup pass through the
 * BelongsToAccount global scope. No withoutGlobalScopes(); a forgotten filter fails
 * closed. Account B can never see Account A's lead activity.
 */
final class EndUserActivityTimeline
{
    // === CONSTANTS ===
    // The subject_type value for events recorded directly about an end user.
    private const SUBJECT_END_USER = EndUser::class;

    // The subject_type value for the generation-lifecycle events.
    private const SUBJECT_GENERATION = Generation::class;

    // Detail keys the recorder curates that make a short human-readable line, in
    // priority order (the first present, non-secret scalar wins). All are non-secret.
    private const DETAIL_KEYS = [
        'to' => 'to',                                 // a status transition target
        'failure_code' => 'failure_code',             // why a generation ended
        'reason' => 'reason',                         // a gate/cancel reason
        'interaction_label' => 'interaction_label',   // a widget interaction label
        'interaction_type' => 'interaction_type',     // the widget interaction type
        'path' => 'path',                             // the page a shopper viewed
        'product_id' => 'product_id',                 // the add-to-cart product
    ];

    /**
     * The lead's activity, newest first. Account-scoped to the lead's own account.
     *
     * @return Collection<int,EndUserActivityItem>
     */
    public function for(EndUser $endUser): Collection
    {
        return Tenant::run((int) $endUser->account_id, function () use ($endUser): Collection {
            $generationIds = $this->generationIds($endUser);

            return ActivityEvent::query()
                ->where(function ($query) use ($endUser, $generationIds): void {
                    // (a) events whose subject IS this end user.
                    $query->where(function ($q) use ($endUser): void {
                        $q->where('subject_type', self::SUBJECT_END_USER)
                            ->where('subject_id', $endUser->getKey());
                    });

                    // (b) events whose subject is one of this lead's generations.
                    if ($generationIds->isNotEmpty()) {
                        $query->orWhere(function ($q) use ($generationIds): void {
                            $q->where('subject_type', self::SUBJECT_GENERATION)
                                ->whereIn('subject_id', $generationIds->all());
                        });
                    }
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ActivityEvent $event): EndUserActivityItem => $this->toItem($event));
        });
    }

    /**
     * The ids of this lead's generations (account-scoped via the global scope).
     * Used to correlate generation-subject activity to the lead.
     *
     * @return Collection<int,int>
     */
    private function generationIds(EndUser $endUser): Collection
    {
        return Generation::query()
            ->where('end_user_id', $endUser->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
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
