<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Banners\BannerEventRecorder;
use App\Http\Requests\Widget\BannerEventRequest;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\JsonResponse;

/**
 * BannerEventController — POST /widget/v1/banners/event. Records one banner impression or click
 * so the merchant sees exact per-banner clicks + CTR. Fire-and-forget: always typed { ok:true }
 * (never a 500). The site is the SERVER-resolved bound tenant, so an event can only ever attach
 * to a banner of THIS shop (the recorder verifies ownership).
 */
final class BannerEventController
{
    public function __construct(
        private readonly BannerEventRecorder $recorder,
    ) {}

    public function __invoke(BannerEventRequest $request): JsonResponse
    {
        $site = WidgetContext::of($request)->site;

        $this->recorder->record(
            $site,
            (int) $request->input(BannerEventRequest::FIELD_BANNER_ID),
            (string) $request->input(BannerEventRequest::FIELD_KIND),
            $request->input(BannerEventRequest::FIELD_ANON_TOKEN),
            $request->input(BannerEventRequest::FIELD_PATH),
        );

        return WidgetResponse::ok(['recorded' => true]);
    }
}
