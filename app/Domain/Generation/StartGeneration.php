<?php

namespace App\Domain\Generation;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Ai\ImagePayload;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Media\MediaStorage;
use App\Models\ActivityEvent;
use App\Models\Generation;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * StartGeneration — the entry point the (Phase-7) widget API calls to begin a try-on.
 *
 * Validates the request invariants it OWNS (use-my-photo consent, product confirmed,
 * variant belongs to the product), records consent, stores the SOURCE photo to the
 * media disk, creates the Generation(pending) with the deterministic idempotency key,
 * and dispatches GenerateTryOnJob — then returns a handle the widget polls.
 *
 * Idempotent at the entry point too: a double-clicked button (same client_request_id
 * -> same key) returns the EXISTING generation and dispatches NO second job, collapsing
 * the double-click before it ever reaches the queue (the four-layer wall, layer 4).
 *
 * Must run inside a bound tenant (Tenant::run) — the HTTP layer binds the account from
 * the resolved site, never from the request body. account_id is stamped by
 * BelongsToAccount; this action never reads the ambient tenant to DECIDE the account.
 */
final class StartGeneration
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly ActivityRecorder $activity,
    ) {}

    public function handle(GenerationRequest $request): StartGenerationResult
    {
        $this->assertStartable($request);

        $endUser = $request->endUser;
        $key = IdempotencyKey::forGeneration(
            accountId: (int) $endUser->account_id,
            siteId: (int) $endUser->site_id,
            endUserId: (int) $endUser->getKey(),
            productId: (int) $request->product->getKey(),
            variant: (array) ($request->variant?->options ?? []),
            clientRequestId: $request->clientRequestId,
        );

        // LAYER 4 at the entry point: a double-click (same key) returns the existing
        // generation and dispatches NO second job.
        $existing = Generation::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return StartGenerationResult::fromGeneration($existing, reused: true);
        }

        $this->recordPhotoConsent($endUser);

        $generation = $this->createPendingWithSource($request, $key);

        // Dispatch the worker job (explicit account_id — never inferred on the worker).
        GenerateTryOnJob::dispatch(
            (int) $endUser->account_id,
            (int) $endUser->site_id,
            (int) $generation->getKey(),
        );

        return StartGenerationResult::fromGeneration($generation, reused: false);
    }

    /**
     * Create the Generation(pending) and store the SOURCE photo under its id. Done in
     * one transaction so a half-created generation (row without its source) never
     * persists. A unique-key race (two simultaneous first-clicks) resolves to the
     * existing row rather than a 500.
     */
    private function createPendingWithSource(GenerationRequest $request, string $key): Generation
    {
        return DB::transaction(function () use ($request, $key): Generation {
            $endUser = $request->endUser;

            $generation = new Generation([
                'site_id' => $endUser->site_id,
                'end_user_id' => $endUser->getKey(),
                'product_id' => $request->product->getKey(),
                'product_variant_id' => $request->variant?->getKey(),
                'status' => Generation::STATUS_PENDING,
                'client_request_id' => $request->clientRequestId,
                'idempotency_key' => $key,
                'meta' => [
                    Generation::META_HEIGHT => $request->userHeight,
                    Generation::META_EXTRA_ATTRS => $request->extraAttrs,
                    Generation::META_VARIANT_SNAPSHOT => (array) ($request->variant?->options ?? []),
                    Generation::META_RETENTION_DAYS => $request->endUser->site?->retention_days
                        ?? Site::DEFAULT_RETENTION_DAYS,
                ],
            ]);
            $generation->save();

            // Store the source under the generation's id (the tenant/site scoped path).
            $stored = $this->media->storeSource(
                (int) $endUser->account_id,
                (int) $endUser->site_id,
                (int) $generation->getKey(),
                $request->photoBytes,
                $request->photoMime,
            );

            $generation->forceFill(['source_image_path' => $stored->path])->save();

            $this->activity->record(
                kind: ActivityEvent::KIND_GENERATION_REQUESTED,
                subject: $generation,
                details: ['client_request_id' => $request->clientRequestId],
                siteId: $generation->site_id,
                actor: ActivityEvent::ACTOR_END_USER,
            );

            return $generation;
        });
    }

    /** Validate the start invariants this action owns (4xx-class, the widget's bug). */
    private function assertStartable(GenerationRequest $request): void
    {
        if (! $request->photoConsent && ! $request->endUser->hasPhotoConsent()) {
            throw GenerationStartException::photoConsentRequired();
        }

        if (! $request->product->isConfirmed()) {
            throw GenerationStartException::productNotConfirmed();
        }

        // A single-SKU product has no variant; when one IS given it must belong to the product.
        if ($request->variant !== null && (int) $request->variant->product_id !== (int) $request->product->getKey()) {
            throw GenerationStartException::variantMismatch();
        }

        // Validate the photo bytes/mime/size up front so a bad upload never reaches the
        // worker (ImagePayload throws a classified bad_request on oversize/wrong mime).
        ImagePayload::fromBytes($request->photoBytes, $request->photoMime);
    }

    /** Stamp the use-my-photo consent timestamp (the provable basis to process the photo). */
    private function recordPhotoConsent(\App\Models\EndUser $endUser): void
    {
        if ($endUser->hasPhotoConsent()) {
            return;
        }

        $endUser->forceFill(['photo_consent_at' => now()])->save();
    }
}
