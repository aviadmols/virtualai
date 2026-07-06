<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Club\ClubVerification;
use App\Http\Requests\Widget\ClubRequestCodeRequest;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\JsonResponse;

/**
 * ClubRequestCodeController — POST /widget/v1/club/request-code. Issues + emails a
 * one-time verification code so the shopper can join the Customer-Club.
 *
 * Always typed JSON { ok:true, ... } (never a 500/HTML). Two happy shapes:
 *  - a fresh request:   { ok:true, code_sent:true }
 *  - inside the anti-spam throttle window: { ok:true, code_sent:false, reason:'throttled' }
 *
 * The site is the SERVER-resolved WidgetContext site (bound tenant); the code store
 * is keyed by that site_id, so site A can never issue against site B. No EndUser row
 * is minted here — a code request is not yet a lead (that happens on verify).
 */
final class ClubRequestCodeController
{
    // === CONSTANTS ===
    public const REASON_THROTTLED = 'throttled';

    public function __construct(
        private readonly ClubVerification $verification,
    ) {}

    public function __invoke(ClubRequestCodeRequest $request): JsonResponse
    {
        $site = WidgetContext::of($request)->site;

        $sent = $this->verification->issue(
            (int) $site->getKey(),
            (string) $request->input(ClubRequestCodeRequest::FIELD_ANON_TOKEN),
            (string) $request->input(ClubRequestCodeRequest::FIELD_EMAIL),
        );

        if (! $sent) {
            return WidgetResponse::ok([
                'code_sent' => false,
                'reason' => self::REASON_THROTTLED,
            ]);
        }

        return WidgetResponse::ok(['code_sent' => true]);
    }
}
