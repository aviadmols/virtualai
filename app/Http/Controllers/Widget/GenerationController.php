<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Generation\GenerationRequest as TryOnRequest;
use App\Domain\Generation\GenerationStartException;
use App\Domain\Generation\StartGeneration;
use App\Domain\Media\MediaStorage;
use App\Http\Requests\Widget\StartGenerationRequest;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\PhotoInput;
use App\Http\Widget\Resources\GenerationPayload;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetGateDecision;
use App\Http\Widget\WidgetGateService;
use App\Http\Widget\WidgetResponse;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GenerationController — POST /widget/v1/generations (start) + GET /widget/v1/generations/{id} (poll).
 *
 * START: the tenant is already bound by the widget-auth middleware. We resolve the end
 * user from the anon_token, load the CONFIRMED product + variant (account-scoped), then
 * run the THREE independent gates as a fast pre-dispatch check (WidgetGateService). A gate
 * denial returns a TYPED result (signup-required / out-of-credits / rate-limited) with the
 * right HTTP status — NEVER a 500 and NEVER a charge (no generation row, no job, no
 * OpenRouter call). On pass we hand a validated GenerationRequest to StartGeneration, which
 * stores the source photo, creates the pending generation, and dispatches the worker.
 *
 * POLL: status + (only when succeeded) a short-lived SIGNED result URL, scoped to
 * (site, anon_token, account) — end user A can never read end user B's generation.
 */
final class GenerationController
{
    public function __construct(
        private readonly EndUserResolver $endUsers,
        private readonly WidgetGateService $gates,
        private readonly StartGeneration $startGeneration,
        private readonly MediaStorage $media,
    ) {}

    /** POST /widget/v1/generations — start a try-on (gated, idempotent, never charges on denial). */
    public function store(StartGenerationRequest $request): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;
        $account = $site->account;

        $endUser = $this->endUsers->resolve($site, (string) $request->input(StartGenerationRequest::FIELD_ANON_TOKEN));

        $product = $this->findProduct($site->getKey(), (int) $request->input(StartGenerationRequest::FIELD_PRODUCT_ID));

        if ($product === null) {
            return WidgetResponse::error('product_not_found', __('widget_api.not_found.product'), WidgetResponse::STATUS_NOT_FOUND);
        }

        $variant = $this->findVariant($product, (int) $request->input(StartGenerationRequest::FIELD_VARIANT_ID));

        if ($variant === null) {
            return WidgetResponse::error('variant_mismatch', __('widget_api.start.variant_mismatch'), WidgetResponse::STATUS_UNPROCESSABLE);
        }

        // --- THE THREE INDEPENDENT GATES (pre-dispatch; a denial never charges) ---
        $decision = $this->gates->check($account, $site, $endUser);

        if ($decision->denied()) {
            return $this->gateDenial($decision);
        }

        // Decode + validate the photo bytes (typed 422 on a bad upload — never to the worker).
        $photo = PhotoInput::decode(
            $request->input(StartGenerationRequest::FIELD_PHOTO),
            $request->file(StartGenerationRequest::FIELD_PHOTO_FILE),
        );

        if ($photo === null) {
            return WidgetResponse::error('photo_invalid', __('widget_api.validation.photo_invalid'), WidgetResponse::STATUS_UNPROCESSABLE);
        }

        try {
            $result = $this->startGeneration->handle(new TryOnRequest(
                endUser: $endUser,
                product: $product,
                variant: $variant,
                photoBytes: $photo['bytes'],
                photoMime: $photo['mime'],
                userHeight: (int) $request->input(StartGenerationRequest::FIELD_HEIGHT),
                clientRequestId: (string) $request->input(StartGenerationRequest::FIELD_CLIENT_REQUEST_ID),
                photoConsent: true, // FormRequest already required `consent` to be accepted
                extraAttrs: $request->extraAttrs(),
            ));
        } catch (GenerationStartException $e) {
            // A 4xx-class start problem (consent / not-confirmed / variant mismatch).
            return WidgetResponse::error($e->reason, __('widget_api.start.'.$e->reason), WidgetResponse::STATUS_UNPROCESSABLE);
        }

        return WidgetResponse::ok([
            'generation' => [
                'id' => $result->generationId,
                'status' => $result->status,
            ],
            'free_remaining' => $decision->freeRemaining,
            'reused' => $result->reused,
        ], WidgetResponse::STATUS_CREATED);
    }

    /** GET /widget/v1/generations/{id} — poll status + (succeeded only) a signed result URL. */
    public function show(Request $request, int $id): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;

        $anonToken = (string) $request->query('anon_token', '');
        $endUser = strlen($anonToken) >= 8 ? $this->endUsers->find($site, $anonToken) : null;

        // Ownership: the generation must belong to this site AND this end user (and the
        // bound account via the global scope). End user A can never read B's generation.
        $generation = $endUser === null ? null : Generation::query()
            ->where('site_id', $site->getKey())
            ->where('end_user_id', $endUser->getKey())
            ->whereKey($id)
            ->first();

        if ($generation === null) {
            return WidgetResponse::error('generation_not_found', __('widget_api.not_found.generation'), WidgetResponse::STATUS_NOT_FOUND);
        }

        return WidgetResponse::ok([
            'generation' => GenerationPayload::make($generation, $this->media),
        ]);
    }

    /** A confirmed product within the bound site (account-scoped by the global scope). */
    private function findProduct(int $siteId, int $productId): ?Product
    {
        return Product::query()
            ->where('site_id', $siteId)
            ->where('status', Product::STATUS_CONFIRMED)
            ->whereKey($productId)
            ->first();
    }

    /** A variant of the given product (account-scoped). */
    private function findVariant(Product $product, int $variantId): ?ProductVariant
    {
        return ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->whereKey($variantId)
            ->first();
    }

    /** Map a typed gate decision to the right typed JSON + HTTP status (never a 500). */
    private function gateDenial(WidgetGateDecision $decision): JsonResponse
    {
        $reason = (string) $decision->reason;
        $message = __('widget_api.gates.'.$reason);

        return match ($reason) {
            WidgetGateDecision::REASON_RATE_LIMITED => $this->rateLimited($decision),
            WidgetGateDecision::REASON_INSUFFICIENT_CREDITS,
            WidgetGateDecision::REASON_ACCOUNT_INACTIVE => WidgetResponse::gate($reason, $message, WidgetResponse::STATUS_PAYMENT_REQUIRED),
            // signup-required / post-signup-limit are still actionable -> 200 so the widget
            // renders the signup screen (not an error).
            default => WidgetResponse::gate($reason, $message, WidgetResponse::STATUS_OK),
        };
    }

    private function rateLimited(WidgetGateDecision $decision): JsonResponse
    {
        $retryAfter = max(1, (int) ($decision->retryAfterSeconds ?? 1));

        $response = WidgetResponse::gate(
            WidgetGateDecision::REASON_RATE_LIMITED,
            __('widget_api.rate_limited'),
            WidgetResponse::STATUS_TOO_MANY,
            ['retry_after' => $retryAfter],
        );

        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
