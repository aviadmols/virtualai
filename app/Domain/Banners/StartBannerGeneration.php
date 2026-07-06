<?php

namespace App\Domain\Banners;

use App\Domain\Ai\ImagePayload;
use App\Domain\Credits\IdempotencyKey;
use App\Domain\Generation\GenerateBannerJob;
use App\Domain\Media\MediaStorage;
use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * StartBannerGeneration — the entry point the merchant editor calls to generate a banner
 * candidate. Mirrors StartGeneration (the try-on entry): it validates the reference image
 * up front, creates the BannerAsset(pending) with the deterministic idempotency key, stores
 * the optional PRIVATE reference under the asset, and dispatches GenerateBannerJob — then
 * returns the pending asset for the editor to poll.
 *
 * Idempotent at the entry point: a double-clicked Generate (same client_request_id -> same
 * key) returns the EXISTING asset and dispatches NO second job. A NEW Generate carries a new
 * client_request_id and mints a fresh candidate, so the merchant's iteration is not deduped.
 *
 * Runs inside a bound tenant (the caller binds it); account_id is stamped by BelongsToAccount.
 * The account is the banner's own account — never inferred from ambient state.
 */
final class StartBannerGeneration
{
    public function __construct(
        private readonly MediaStorage $media,
    ) {}

    public function handle(BannerGenerationRequest $request): BannerAsset
    {
        $banner = $request->banner;

        $key = IdempotencyKey::forBanner(
            accountId: (int) $banner->account_id,
            siteId: (int) $banner->site_id,
            bannerId: (int) $banner->getKey(),
            clientRequestId: $request->clientRequestId,
        );

        // Entry-point idempotency: a repeat of the same Generate click returns the existing
        // asset and dispatches no second job.
        $existing = BannerAsset::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Validate the reference bytes/mime/size up front so a bad upload never reaches the
        // worker (ImagePayload throws a classified bad_request on oversize/wrong mime).
        if ($request->hasReference()) {
            ImagePayload::fromBytes((string) $request->referenceBytes, (string) $request->referenceMime);
        }

        return $this->createPendingWithSource($request, $banner, $key);
    }

    /**
     * Create the BannerAsset(pending) and store the optional reference under its id, in one
     * transaction so a half-created asset never persists. Then dispatch the worker with an
     * EXPLICIT account_id (never inferred on the worker).
     */
    private function createPendingWithSource(BannerGenerationRequest $request, Banner $banner, string $key): BannerAsset
    {
        $asset = DB::transaction(function () use ($request, $banner, $key): BannerAsset {
            $asset = new BannerAsset([
                'site_id' => $banner->site_id,
                'banner_id' => $banner->getKey(),
                'status' => BannerAsset::STATUS_PENDING,
                'client_request_id' => $request->clientRequestId,
                'idempotency_key' => $key,
                'brief' => $request->brief,
                'meta' => [
                    BannerAsset::META_RETENTION_DAYS => $banner->site?->retention_days
                        ?? Site::DEFAULT_RETENTION_DAYS,
                ],
            ]);
            $asset->save();

            if ($request->hasReference()) {
                $stored = $this->media->storeBannerSource(
                    (int) $banner->account_id,
                    (int) $banner->site_id,
                    (int) $asset->getKey(),
                    (string) $request->referenceBytes,
                    (string) $request->referenceMime,
                );

                $asset->forceFill(['source_image_path' => $stored->path])->save();
            }

            return $asset;
        });

        GenerateBannerJob::dispatch(
            (int) $banner->account_id,
            (int) $banner->site_id,
            (int) $asset->getKey(),
        );

        return $asset;
    }
}
