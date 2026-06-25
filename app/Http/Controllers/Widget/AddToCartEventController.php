<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Activity\ActivityRecorder;
use App\Http\Requests\Widget\AddToCartEventRequest;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use Illuminate\Http\JsonResponse;

/**
 * AddToCartEventController — POST /widget/v1/events/add-to-cart. Records the funnel event.
 *
 * The actual cart add is the HOST platform's job (Phase 7b bridges it); the backend only
 * advances the lead funnel generated -> added_to_cart and leaves an activity trace. The
 * EndUser transition is FORWARD-ONLY + guarded: we only advance when the user is currently
 * `generated` (a `new` user who never generated can't legally jump to added_to_cart). The
 * event is recorded either way so attribution isn't lost.
 */
final class AddToCartEventController
{
    public function __construct(
        private readonly EndUserResolver $endUsers,
        private readonly ActivityRecorder $activity,
    ) {}

    public function __invoke(AddToCartEventRequest $request): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;

        $endUser = $this->endUsers->resolve($site, (string) $request->input(AddToCartEventRequest::FIELD_ANON_TOKEN));

        $advanced = $this->advanceFunnel($endUser);

        $this->activity->record(
            kind: ActivityEvent::KIND_LEAD_ADDED_TO_CART,
            subject: $endUser,
            details: array_filter([
                'generation_id' => $request->input(AddToCartEventRequest::FIELD_GENERATION_ID),
                'variant_id' => $request->input(AddToCartEventRequest::FIELD_VARIANT_ID),
            ], static fn ($v) => $v !== null),
            siteId: $site->getKey(),
            actor: ActivityEvent::ACTOR_END_USER,
        );

        return WidgetResponse::ok([
            'recorded' => true,
            'status' => $endUser->status,
            'advanced' => $advanced,
        ]);
    }

    /**
     * Advance generated -> added_to_cart when legal. The forward-only guard rejects an
     * illegal jump (e.g. from `new`); we treat that as a no-op record, not a 500.
     */
    private function advanceFunnel(EndUser $endUser): bool
    {
        if ($endUser->status !== EndUser::STATUS_GENERATED) {
            return false;
        }

        $endUser->transitionTo(EndUser::STATUS_ADDED_TO_CART);

        return true;
    }
}
