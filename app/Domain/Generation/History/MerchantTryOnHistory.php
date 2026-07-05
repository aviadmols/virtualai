<?php

namespace App\Domain\Generation\History;

use App\Domain\Media\MediaStorage;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * MerchantTryOnHistory — the read-side of a shop's try-on history for the merchant
 * panel (WS2). Returns a list of immutable TryOnHistoryItem DTOs (newest first),
 * each with a short-lived signed thumbnail URL or a `purged`/placeholder flag.
 * Mirrors MerchantGalleryQuery / LeadAttemptHistory exactly.
 *
 * SCOPE: per-SITE — the merchant sees every try-on (the "mechanism's activations")
 * for the CURRENT shop, ALL statuses (success and non-success), so a failed or
 * cancelled attempt is visible here (the gallery, by contrast, is succeeded-only).
 *
 * Tenant-safety: the site's generations are read through the BelongsToAccount
 * global scope inside Tenant::run($site->account_id) — so the query is isolated to
 * the site's own account; a generation belonging to another account can never
 * appear. No withoutGlobalScopes(); a forgotten filter fails closed. The signed
 * URL is minted via MediaStorage (short TTL from config); a purged/absent result
 * surfaces as `purged` so the UI never shows a broken image.
 */
final class MerchantTryOnHistory
{
    // === CONSTANTS ===
    // One page of history — enough to review recent activity without a heavy query.
    private const DEFAULT_PER_PAGE = 30;

    public function __construct(
        private readonly MediaStorage $media,
    ) {}

    /** The default page size — the load-more accumulator on the page reads this. */
    public static function defaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }

    /**
     * A shop's try-on history, newest first, page $page of $perPage. Account-scoped
     * to the site's own account. Returns the mapped DTOs plus paging facts so the
     * page can render "load more" without exposing a live model or a cross-account
     * count.
     *
     * @return array{items:Collection<int,TryOnHistoryItem>,total:int,perPage:int,page:int,hasMore:bool}
     */
    public function forSite(Site $site, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return Tenant::run((int) $site->account_id, function () use ($site, $page, $perPage): array {
            $base = Generation::query()->where('site_id', $site->getKey());

            $total = (clone $base)->count();

            $items = $base
                ->with(['endUser:id,full_name,email', 'product:id,name', 'variant:id,options'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get()
                ->map(fn (Generation $generation): TryOnHistoryItem => $this->toItem($generation));

            return [
                'items' => $items,
                'total' => $total,
                'perPage' => $perPage,
                'page' => $page,
                'hasMore' => $page * $perPage < $total,
            ];
        });
    }

    /** Map one generation to the immutable history row (with a signed thumbnail). */
    private function toItem(Generation $generation): TryOnHistoryItem
    {
        $hasResult = $generation->result_image_path !== null
            && $generation->result_image_path !== '';

        // A succeeded generation whose result bytes are no longer reachable on disk has
        // been purged (retention) — or the disk is momentarily unreachable. A failed
        // attempt never produced one. Either way it degrades to the placeholder, never a
        // broken thumb and never a 500.
        [$objectExists, $thumbnailUrl] = $this->resolveThumbnail(
            $hasResult ? $generation->result_image_path : null,
        );
        $purged = $generation->isSucceeded() && ! $objectExists;

        return new TryOnHistoryItem(
            generationId: (int) $generation->getKey(),
            status: (string) $generation->status,
            endUserId: $generation->end_user_id !== null ? (int) $generation->end_user_id : null,
            endUserName: $this->endUserName($generation->endUser),
            productName: $generation->product?->name,
            variantOptions: $this->variantOptions($generation),
            resultThumbnailUrl: $thumbnailUrl,
            purged: $purged,
            failureCode: $generation->failure_code,
            createdAt: $generation->created_at?->toIso8601String(),
        );
    }

    /** The lead's display label: full name → email → null (anonymous). */
    private function endUserName(?EndUser $endUser): ?string
    {
        if ($endUser === null) {
            return null;
        }

        $name = trim((string) ($endUser->full_name ?? ''));

        return $name !== '' ? $name : ($endUser->email ?: null);
    }

    /**
     * Resolve the result thumbnail defensively: returns [objectExists, signedUrl|null].
     * Any media-disk failure — a purged object, or a misconfigured/unreachable disk in
     * dev — collapses to [false, null] so the history shows the placeholder instead of
     * surfacing a 500 from the storage driver.
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
