<?php

namespace App\Domain\Banners;

use App\Models\Banner;
use App\Models\BannerEvent;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BannerEventRecorder — the single writer of banner_events (impression | click).
 *
 * Best-effort + append-only: it SWALLOWS its own exceptions (analytics must never break the
 * storefront) and only records for a banner that belongs to the BOUND site — a client cannot
 * log an event against another shop's banner. account_id is stamped by BelongsToAccount from
 * the bound tenant; the row is the exact per-banner count the merchant sees.
 */
final class BannerEventRecorder
{
    private const LOG_FAILED = 'banner.event_record_failed';

    public function record(Site $site, int $bannerId, string $kind, ?string $anonToken, ?string $path): void
    {
        if (! in_array($kind, BannerEvent::KINDS, true)) {
            return;
        }

        // The banner must belong to THIS site (account-scoped by the bound tenant + site_id) —
        // a forged banner_id for another shop records nothing.
        $belongs = Banner::query()->where('site_id', $site->getKey())->whereKey($bannerId)->exists();

        if (! $belongs) {
            return;
        }

        try {
            BannerEvent::create([
                'site_id' => $site->getKey(),
                'banner_id' => $bannerId,
                'kind' => $kind,
                'path' => $path,
                'anon_token' => $anonToken,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning(self::LOG_FAILED, ['banner_id' => $bannerId, 'error' => $e->getMessage()]);
        }
    }
}
