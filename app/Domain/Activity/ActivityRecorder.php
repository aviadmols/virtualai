<?php

namespace App\Domain\Activity;

use App\Models\ActivityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ActivityRecorder — the single writer of the Timeline (activity_events).
 *
 * CONTRACT: it SWALLOWS its own exceptions. A trace write must NEVER block or roll
 * back the money path — if the timeline insert fails, the charge/grant still
 * stands. The failure is logged (not re-thrown). Phase 6 builds the read side.
 *
 * Every event is account-scoped; account_id is stamped by BelongsToAccount from
 * the bound tenant. A subject (Generation, EndUser, Account) is optional and
 * recorded polymorphically.
 */
final class ActivityRecorder
{
    // === CONSTANTS ===
    private const LOG_CHANNEL_MESSAGE = 'activity.record_failed';

    /**
     * Record one timeline event about an optional subject. Returns the row, or
     * null if the write failed (swallowed). Never throws.
     *
     * @param  array<string,mixed>  $details
     */
    public function record(
        string $kind,
        ?Model $subject = null,
        array $details = [],
        ?int $siteId = null,
        string $actor = ActivityEvent::ACTOR_SYSTEM,
    ): ?ActivityEvent {
        try {
            return ActivityEvent::create([
                'site_id' => $siteId,
                'actor' => $actor,
                'kind' => $kind,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'details' => $details,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Swallow: the timeline is best-effort and must not break the money path.
            Log::warning(self::LOG_CHANNEL_MESSAGE, [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
