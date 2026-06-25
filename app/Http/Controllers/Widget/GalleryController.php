<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Media\MediaStorage;
use App\Http\Widget\EndUserResolver;
use App\Http\Widget\Resources\GenerationPayload;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GalleryController — GET /widget/v1/gallery. The end user's SESSION try-ons for the
 * slider, scoped to (site, anon_token, account). Persists across reload + across the
 * site's PDPs within the retention window (keyed by the same anon_token the lead gate
 * uses — answers Q-GALLERY: yes, it persists).
 *
 * Returns only SUCCEEDED generations (a gallery tile needs a result), each with a
 * short-lived SIGNED result URL (never a public path). Capped + ordered newest-first. End
 * user A can never read B's gallery (scoped by end_user_id + the account global scope).
 */
final class GalleryController
{
    // === CONSTANTS ===
    private const PER_PAGE_CONFIG = 'widget.gallery.per_page';
    private const MAX_PER_PAGE_CONFIG = 'widget.gallery.max_per_page';
    private const QUERY_LIMIT = 'limit';

    public function __construct(
        private readonly EndUserResolver $endUsers,
        private readonly MediaStorage $media,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = WidgetContext::of($request);
        $site = $context->site;

        $anonToken = (string) $request->query('anon_token', '');
        $endUser = strlen($anonToken) >= 8 ? $this->endUsers->find($site, $anonToken) : null;

        // No resolved end user -> a clean EMPTY gallery (never a 404, never a broken grid).
        if ($endUser === null) {
            return WidgetResponse::ok(['items' => []]);
        }

        $items = Generation::query()
            ->where('site_id', $site->getKey())
            ->where('end_user_id', $endUser->getKey())
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->orderByDesc('id')
            ->limit($this->limit($request))
            ->get()
            ->map(fn (Generation $generation): array => GenerationPayload::make($generation, $this->media))
            ->all();

        return WidgetResponse::ok(['items' => $items]);
    }

    /** The clamped page size (config default, hard max). */
    private function limit(Request $request): int
    {
        $default = (int) config(self::PER_PAGE_CONFIG);
        $max = (int) config(self::MAX_PER_PAGE_CONFIG);

        $requested = (int) $request->query(self::QUERY_LIMIT, $default);

        return max(1, min($requested, $max));
    }
}
