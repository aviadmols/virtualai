<?php

namespace App\Domain\Leads;

use App\Domain\Media\MediaStorage;
use App\Models\EndUser;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * LeadAttemptHistory — the read-side of one lead's try-on history for the A7 lead
 * card. Returns a list of immutable LeadAttempt DTOs (newest first), each with a
 * short-lived signed thumbnail URL or a purged flag.
 *
 * Tenant-safety: the lead's generations are read through the BelongsToAccount global
 * scope inside Tenant::run($endUser->account_id) — so the query is isolated to the
 * lead's own account; a generation belonging to another account can never appear.
 * No withoutGlobalScopes(); a forgotten filter fails closed.
 *
 * The signed URL is minted via MediaStorage (short TTL from config). The result bytes
 * having been purged by retention is surfaced as `purged` (the path is gone or the
 * object no longer exists on the disk) so the UI shows leads.history.purged, never a
 * broken image.
 */
final class LeadAttemptHistory
{
    public function __construct(
        private readonly MediaStorage $media,
    ) {}

    /**
     * The lead's attempts, newest first. Account-scoped to the lead's own account.
     *
     * @return Collection<int,LeadAttempt>
     */
    public function for(EndUser $endUser): Collection
    {
        return Tenant::run((int) $endUser->account_id, function () use ($endUser): Collection {
            return Generation::query()
                ->with(['product:id,name', 'variant:id,options'])
                ->where('end_user_id', $endUser->getKey())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Generation $generation): LeadAttempt => $this->toAttempt($generation));
        });
    }

    /** Map one generation to the immutable A7 attempt row (with a signed thumbnail). */
    private function toAttempt(Generation $generation): LeadAttempt
    {
        $hasResult = $generation->result_image_path !== null
            && $generation->result_image_path !== '';

        // A succeeded generation whose result bytes are no longer reachable on disk has
        // been purged (retention) — or the disk is momentarily unreachable. Either way it
        // degrades to the placeholder, never a broken image and never a 500.
        [$objectExists, $thumbnailUrl] = $this->resolveThumbnail(
            $hasResult ? $generation->result_image_path : null,
        );
        $purged = $generation->isSucceeded() && ! $objectExists;

        return new LeadAttempt(
            generationId: (int) $generation->getKey(),
            status: (string) $generation->status,
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
     * dev — collapses to [false, null] so the lead card shows the purged placeholder
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
     * The selected variant options: prefer the live variant, fall back to the
     * snapshot stored in meta (the variant row may have been deleted on a re-scan).
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
