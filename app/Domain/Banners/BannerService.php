<?php

namespace App\Domain\Banners;

use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\Site;

/**
 * BannerService — the single validated writer of a banner's content + lifecycle (mirrors
 * SiteSettingsService). Every merchant edit routes through here so validation lives in ONE
 * place: a bad value throws a typed InvalidBannerException (the editor renders a soft field
 * error) and NOTHING is persisted. Runs inside a bound tenant; account_id is stamped by
 * BelongsToAccount — the account is never read from ambient state to DECIDE ownership.
 *
 * Placements (Phase 3) + rules (Phase 4) have their own validated writers on this service.
 */
final class BannerService
{
    /** Create a new DRAFT banner for a shop from a validated name. */
    public function createDraft(Site $site, string $name): Banner
    {
        $clean = BannerContent::sanitize([BannerContent::KEY_NAME => $name]);

        $banner = new Banner([
            'site_id' => $site->getKey(),
            'name' => $clean[BannerContent::KEY_NAME],
            'status' => Banner::STATUS_DRAFT,
            'composition' => Banner::COMPOSITION_IMAGE,
        ]);
        $banner->save();

        return $banner;
    }

    /**
     * Apply a validated content patch (name / composition / target_url / overlay / alt_text).
     * Only the keys present in $patch are changed.
     *
     * @param  array<string,mixed>  $patch
     */
    public function updateContent(Banner $banner, array $patch): Banner
    {
        $clean = BannerContent::sanitize($patch);

        if ($clean !== []) {
            $banner->fill($clean);
            $banner->save();
        }

        return $banner;
    }

    /**
     * Select a generated candidate as the banner's artwork: copy its PUBLIC image (path + mime
     * + dims) onto the banner and point selected_asset_id at it. The asset must belong to this
     * banner and be a succeeded generation with a stored image — else a typed rejection.
     */
    public function selectAsset(Banner $banner, BannerAsset $asset): Banner
    {
        if ((int) $asset->banner_id !== (int) $banner->getKey()
            || ! $asset->isSucceeded()
            || $asset->image_path === null || $asset->image_path === '') {
            throw InvalidBannerException::make('selected_asset_id', InvalidBannerException::REASON_ASSET_NOT_SELECTABLE, 'the chosen candidate is not a finished image for this banner');
        }

        $banner->forceFill([
            'selected_asset_id' => $asset->getKey(),
            'image_path' => $asset->image_path,
            'image_mime' => $asset->image_mime,
            'image_width' => $asset->image_width,
            'image_height' => $asset->image_height,
        ])->save();

        return $banner;
    }

    /**
     * Move the banner's status through the guarded machine. Activating requires a selected
     * artwork (a banner cannot go live blank) — else a typed rejection, never a 500.
     */
    public function setStatus(Banner $banner, string $status): Banner
    {
        if ($status === Banner::STATUS_ACTIVE && ! $banner->hasArtwork()) {
            throw InvalidBannerException::make('status', InvalidBannerException::REASON_NO_ARTWORK, 'select a generated image before activating the banner');
        }

        $banner->transitionTo($status);

        return $banner;
    }
}
