<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Leads\LeadCapture;
use App\Domain\Leads\LeadGate;
use App\Http\Requests\Widget\CaptureLeadRequest;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\JsonResponse;

/**
 * LeadController — POST /widget/v1/leads. The signup that re-opens the LeadGate.
 *
 * Captures full_name + email + (optional) phone for the anon_token's end user, stamps
 * registered_at, and applies marketing_consent ONLY from an explicit truthy value
 * (default OFF — GDPR). Returns the NEW allowance (the post-signup grant the LeadGate now
 * reports) so the widget can resume the pending try-on with the updated free-tries chip.
 *
 * Independent of the credit gate: registering re-opens the LEAD gate only; if the merchant
 * is out of credits the next generate still returns out-of-credits (the gates never
 * collapse).
 */
final class LeadController
{
    public function __construct(
        private readonly EndUserResolver $endUsers,
        private readonly LeadCapture $capture,
    ) {}

    public function __invoke(CaptureLeadRequest $request): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;

        $endUser = $this->endUsers->resolve($site, (string) $request->input(CaptureLeadRequest::FIELD_ANON_TOKEN));

        $endUser = $this->capture->register($endUser, [
            'full_name' => $request->input(CaptureLeadRequest::FIELD_FULL_NAME),
            'email' => $request->input(CaptureLeadRequest::FIELD_EMAIL),
            'phone' => $request->input(CaptureLeadRequest::FIELD_PHONE),
            'source' => $request->input(CaptureLeadRequest::FIELD_SOURCE),
            'utm' => $request->input(CaptureLeadRequest::FIELD_UTM),
            // Default OFF; only an explicit true opts in (LeadCapture enforces this too).
            'marketing_consent' => $request->boolean(CaptureLeadRequest::FIELD_MARKETING_CONSENT),
        ]);

        // The re-opened allowance: ask the LeadGate again now that the lead is registered.
        $decision = LeadGate::for($site, $endUser)->assertCanTry();

        return WidgetResponse::ok([
            'lead' => [
                'registered' => $endUser->isRegistered(),
                'marketing_consent' => $endUser->hasMarketingConsent(),
            ],
            'allowance' => [
                'allowed' => $decision->allowed,
                'free_remaining' => $decision->freeRemaining,
                'signup_required' => $decision->signupRequired,
            ],
        ], WidgetResponse::STATUS_CREATED);
    }
}
