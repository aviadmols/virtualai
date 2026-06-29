<?php

namespace App\Domain\Gallery;

use App\Domain\Media\MediaStorage;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * MerchantGalleryQuery — the read-side of a site's try-on gallery for the merchant panel.
 * Returns a list of immutable GalleryItem DTOs (newest first), each with a short-lived
 * signed thumbnail URL or a `purged` flag. Mirrors LeadAttemptHistory exactly.
 *
 * SCOPE: per-SITE by default (a merchant browses one site's generations), with an OPTIONAL
 * EndUser filter to drill into one shopper. The gallery shows SUCCEEDED generations only —
 * the merchant gallery is the wall of produced try-ons, not the failure log (the timeline
 * covers failures).
 *
 * Tenant-safety: the site's generations are read through the BelongsToAccount global scope
 * inside Tenant::run($site->account_id) — so the query is isolated to the site's own
 * account; a generation belonging to another account can never appear. No
 * withoutGlobalScopes(); a forgotten filter fails closed. The signed URL is minted via
 * MediaStorage (short TTL from config); a purged result surfaces as `purged`.
 */
final class MerchantGalleryQuery
{
    // === CONSTANTS ===
    // The merchant gallery is the wall of produced try-ons (succeeded only).
    private const GALLERY_STATUS = Generation::STATUS_SUCCEEDED;

    private const DEFAULT_LIMIT = 60;

    public function __construct(
        private readonly MediaStorage $media,
    ) {}

    /**
     * A site's gallery, newest first. Account-scoped to the site's own account; optionally
     * narrowed to one end user. Capped at $limit to keep one page lean.
     *
     * @return Collection<int,GalleryItem>
     */
    public function forSite(Site $site, ?EndUser $endUser = null, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return Tenant::run((int) $site->account_id, function () use ($site, $endUser, $limit): Collection {
            return Generation::query()
                ->with(['product:id,name', 'variant:id,options'])
                ->where('site_id', $site->getKey())
                ->where('status', self::GALLERY_STATUS)
                ->when($endUser !== null, fn ($q) => $q->where('end_user_id', $endUser->getKey()))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (Generation $generation): GalleryItem => $this->toItem($generation));
        });
    }

    /** Map one generation to the immutable gallery item (with a signed thumbnail). */
    private function toItem(Generation $generation): GalleryItem
    {
        $hasResult = $generation->result_image_path !== null
            && $generation->result_image_path !== '';

        // A succeeded generation whose result bytes are no longer reachable on disk has
        // been purged (retention) — or the disk is momentarily unreachable. Either way it
        // degrades to the purged placeholder, never a broken thumb and never a 500.
        [$objectExists, $thumbnailUrl] = $this->resolveThumbnail(
            $hasResult ? $generation->result_image_path : null,
        );
        $purged = $generation->isSucceeded() && ! $objectExists;

        return new GalleryItem(
            generationId: (int) $generation->getKey(),
            status: (string) $generation->status,
            endUserId: $generation->end_user_id !== null ? (int) $generation->end_user_id : null,
            productName: $generation->product?->name,
            variantOptions: $this->variantOptions($generation),
            resultThumbnailUrl: $thumbnailUrl,
            purged: $purged,
            failureCode: $generation->failure_code,
            createdAt: $generation->created_at?->toIso8601String(),
        );
    }

    /**
     * Resolve the result thumbnail defensively: returns [objectExists, signedUrl|null].
     * Any media-disk failure — a purged object, or a misconfigured/unreachable disk in
     * dev — collapses to [false, null] so the gallery shows the purged placeholder
     * instead of surfacing a 500 from the storage driver.
     *
     * @return array{0:bool,1:?string}
     */
    private function resolveThumbnail(?string $path): array
    {
        if ($path === null || $path === '') {
            return [false, null];
        }

        try {
            if (! $this->media->exists($path)) {
                return [false, null];
            }

            return [true, $this->media->signedUrl($path)];
        } catch (\Throwable) {
            return [false, null];
        }
    }

    /**
     * The selected variant options: prefer the live variant, fall back to the snapshot
     * stored in meta (the variant row may have been deleted on a re-scan).
     *
     * @return array<string,mixed>
     */
    private function variantOptions(Generation $generation): array
    {
        if ($generation->variant !== null && is_array($generation->variant->options)) {
            return $generation->variant->options;
        }

        $snapshot = $generation->meta[Generation::META_VARIANT_SNAPSHOT] ?? [];

        return is_array($snapshot) ? $snapshot : [];
    }
}
