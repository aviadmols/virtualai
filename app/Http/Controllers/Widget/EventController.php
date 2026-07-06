<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Activity\ActivityRecorder;
use App\Http\Requests\Widget\EventRequest;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use App\Models\ActivityEvent;
use Illuminate\Http\JsonResponse;

/**
 * EventController — POST /widget/v1/events. Records shopper page views + meaningful
 * interactions as activity events tied to the EndUser (Phase 1d).
 *
 * Fire-and-forget: the response is ALWAYS a typed { ok:true } (never a 500/HTML). The
 * batch is capped at MAX_EVENTS; extras are ignored. Each event is curated defensively
 * — an unknown kind, a missing path, or a bad shape is dropped, not 422'd — and only
 * NON-SECRET scalars are persisted (path normalized/capped, referrer_host, interaction
 * type + label). Behavioral events are PII: no raw query strings, full URLs, or dumps.
 *
 * The EndUser is resolved from the opaque anon_token (get-or-create, mirroring the
 * add-to-cart funnel event) inside the bound tenant — BelongsToAccount stamps
 * account_id and keeps the lookup account-scoped. ActivityRecorder swallows its own
 * exceptions, so a failed trace never breaks ingest.
 */
final class EventController
{
    // === CONSTANTS ===
    // Curated detail keys persisted per event (all non-secret scalars).
    private const DETAIL_PATH = 'path';

    private const DETAIL_REFERRER_HOST = 'referrer_host';

    private const DETAIL_INTERACTION_TYPE = 'interaction_type';

    private const DETAIL_INTERACTION_LABEL = 'interaction_label';

    public function __construct(
        private readonly EndUserResolver $endUsers,
        private readonly ActivityRecorder $activity,
    ) {}

    public function __invoke(EventRequest $request): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;

        $events = $this->cappedEvents($request);

        // Nothing usable — still a clean fire-and-forget ok (no lead minted for an
        // empty/garbage batch).
        if ($events === []) {
            return WidgetResponse::ok(['recorded' => 0]);
        }

        $endUser = $this->endUsers->resolve($site, (string) $request->input(EventRequest::FIELD_ANON_TOKEN));

        $recorded = 0;
        foreach ($events as $event) {
            $curated = $this->curate($event);
            if ($curated === null) {
                continue;   // unknown kind / missing path / bad shape — dropped, not fatal
            }

            $this->activity->record(
                kind: $curated['kind'],
                subject: $endUser,
                details: $curated['details'],
                siteId: $site->getKey(),
                actor: ActivityEvent::ACTOR_END_USER,
            );
            $recorded++;
        }

        return WidgetResponse::ok(['recorded' => $recorded]);
    }

    /**
     * The first MAX_EVENTS array entries of the batch; extras are ignored. Non-array
     * entries are tolerated (curate() drops them). Never throws.
     *
     * @return array<int,mixed>
     */
    private function cappedEvents(EventRequest $request): array
    {
        $events = $request->input(EventRequest::FIELD_EVENTS, []);

        if (! is_array($events)) {
            return [];
        }

        return array_slice(array_values($events), 0, EventRequest::MAX_EVENTS);
    }

    /**
     * Validate + curate one raw event into a non-secret detail bag, or null to drop it.
     * Only whitelisted scalars survive; path is normalized (query/fragment stripped) and
     * every field is length-capped before it can reach the recorder.
     *
     * @return array{kind:string,details:array<string,string>}|null
     */
    private function curate(mixed $event): ?array
    {
        if (! is_array($event)) {
            return null;
        }

        $kind = $event[EventRequest::KEY_KIND] ?? null;
        if (! is_string($kind) || ! in_array($kind, EventRequest::ALLOWED_KINDS, true)) {
            return null;
        }

        $path = $this->normalizePath($event[EventRequest::KEY_PATH] ?? null);
        if ($path === null) {
            return null;   // path is the minimum meaningful signal; without it the row is noise
        }

        $details = [self::DETAIL_PATH => $path];

        $referrerHost = $this->cleanHost($event[EventRequest::KEY_REFERRER_HOST] ?? null);
        if ($referrerHost !== null) {
            $details[self::DETAIL_REFERRER_HOST] = $referrerHost;
        }

        if ($kind === ActivityEvent::KIND_INTERACTION) {
            $this->addInteractionDetails($details, $event[EventRequest::KEY_INTERACTION] ?? null);
        }

        return ['kind' => $kind, 'details' => $details];
    }

    /**
     * Normalize a client-sent path to a bare, non-secret path: strip any query string +
     * fragment (they can carry PII / tokens), trim, cap length. Null when empty.
     */
    private function normalizePath(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        // Drop everything from the first ? or # — no raw query strings / fragments.
        $path = preg_split('/[?#]/', $raw, 2)[0] ?? '';
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        return mb_substr($path, 0, EventRequest::MAX_PATH);
    }

    /** A trimmed, length-capped host string, or null when empty/non-scalar. */
    private function cleanHost(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $host = trim($raw);

        return $host === '' ? null : mb_substr($host, 0, EventRequest::MAX_REFERRER_HOST);
    }

    /**
     * Merge the interaction's type + label (curated scalars, capped) into the detail bag.
     *
     * @param  array<string,string>  $details
     */
    private function addInteractionDetails(array &$details, mixed $interaction): void
    {
        if (! is_array($interaction)) {
            return;
        }

        $type = $this->cleanScalar($interaction[EventRequest::KEY_INTERACTION_TYPE] ?? null, EventRequest::MAX_INTERACTION_TYPE);
        if ($type !== null) {
            $details[self::DETAIL_INTERACTION_TYPE] = $type;
        }

        $label = $this->cleanScalar($interaction[EventRequest::KEY_INTERACTION_LABEL] ?? null, EventRequest::MAX_INTERACTION_LABEL);
        if ($label !== null) {
            $details[self::DETAIL_INTERACTION_LABEL] = $label;
        }
    }

    /** A trimmed, length-capped string from a raw scalar, or null when empty/non-string. */
    private function cleanScalar(mixed $raw, int $max): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
