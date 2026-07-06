<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Club\ClubMembership;
use App\Domain\Club\ClubVerification;
use App\Domain\Club\ClubVerifyResult;
use App\Http\Requests\Widget\ClubVerifyCodeRequest;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\JsonResponse;

/**
 * ClubVerifyCodeController — POST /widget/v1/club/verify-code. Confirms the one-time
 * code and, on a match, makes the shopper a verified Customer-Club member.
 *
 * Always typed JSON { ok:true, ... } (never a 500/HTML). A wrong/expired/over-cap code
 * is a business outcome, not an error:
 *  - match:   { ok:true, verified:true,  member:{ verified:true } }
 *  - miss:    { ok:true, verified:false, reason:'invalid'|'expired'|'locked' }
 *
 * The EndUser is resolved/created ONLY on a successful match (a failed guess must not
 * mint a lead). The site is the SERVER-resolved WidgetContext site (bound tenant), so
 * membership is stamped account-scoped — site A can never verify site B's shopper.
 */
final class ClubVerifyCodeController
{
    public function __construct(
        private readonly ClubVerification $verification,
        private readonly ClubMembership $membership,
        private readonly EndUserResolver $endUsers,
    ) {}

    public function __invoke(ClubVerifyCodeRequest $request): JsonResponse
    {
        $site = WidgetContext::of($request)->site;
        $anonToken = (string) $request->input(ClubVerifyCodeRequest::FIELD_ANON_TOKEN);
        $email = (string) $request->input(ClubVerifyCodeRequest::FIELD_EMAIL);

        $result = $this->verification->verify(
            (int) $site->getKey(),
            $anonToken,
            $email,
            (string) $request->input(ClubVerifyCodeRequest::FIELD_CODE),
        );

        if ($result !== ClubVerifyResult::Verified) {
            return WidgetResponse::ok([
                'verified' => false,
                'reason' => $result->value,
            ]);
        }

        // Only NOW (a proven match) resolve/create the lead and stamp membership.
        $endUser = $this->endUsers->resolve($site, $anonToken);
        $this->membership->join($endUser, $email);

        return WidgetResponse::ok([
            'verified' => true,
            'member' => ['verified' => true],
        ]);
    }
}
