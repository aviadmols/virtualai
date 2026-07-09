<?php

namespace App\Domain\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MediaStorage — the single gateway for try-on image bytes.
 *
 * Writes the shopper SOURCE photo + the generated RESULT image to the S3/R2 media
 * disk under a tenant + site scoped path, and mints SHORT-lived signed URLs for the
 * widget. The disk + signed TTL come from config (config/trayon.php media.*), never
 * a literal. The persisted ref is the opaque disk PATH, not a public URL — a browser
 * only ever receives a signed, expiring URL (so a leaked URL can't be hot-linked).
 *
 * Path shape: accounts/{account}/sites/{site}/generations/{generation}/{kind}-{rand}.{ext}
 * — account_id leads every path so an object can never be mistaken across tenants,
 * and the retention purge (Phase 9) can delete a whole generation prefix.
 *
 * Images are written PRIVATE (no public visibility). The disk is faked in tests
 * (Storage::fake('s3')); no real S3 call ever runs in the suite.
 */
final class MediaStorage
{
    // === CONSTANTS ===
    private const DISK_CONFIG_KEY = 'trayon.media.disk';
    private const SIGNED_TTL_CONFIG_KEY = 'trayon.media.signed_ttl';

    // When the media disk is a LOCAL disk (a Railway Volume), the mount is outside public/
    // so URLs point at the app's media routes instead of the object-store CDN: public banners
    // via a plain cacheable path, private objects via an expiring SIGNED route.
    private const LOCAL_DRIVER = 'local';
    private const ROUTE_SIGNED = 'media.signed';
    private const PUBLIC_PATH_PREFIX = '/media/pub/';

    // Path segments — account leads so an object is never cross-tenant ambiguous.
    private const PATH_ACCOUNTS = 'accounts';
    private const PATH_SITES = 'sites';
    private const PATH_GENERATIONS = 'generations';
    private const PATH_BANNERS = 'banners';

    // The two object kinds a generation stores.
    public const KIND_SOURCE = 'source';
    public const KIND_RESULT = 'result';

    // Banner object kinds. The generated banner is PUBLIC marketing media (shown to every
    // shopper by a stable URL); the optional reference upload stays PRIVATE.
    public const KIND_BANNER = 'banner';
    public const KIND_BANNER_SOURCE = 'banner-source';

    // Playground (Super-Admin model test) result media prefix — NOT tenant-scoped.
    private const PATH_PLAYGROUND = 'playground';

    // Storyboard (admin pre-production builder) media prefix — NOT tenant-scoped.
    private const PATH_STORYBOARD = 'storyboard';
    private const PATH_FRAMES = 'frames';

    // mime -> extension (the disk key carries a sane extension for the CDN).
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
    ];
    private const DEFAULT_EXTENSION = 'bin';

    private const UNKNOWN_PATH_MESSAGE = 'Cannot sign a null/empty media path.';

    /**
     * Store the shopper SOURCE photo for a generation. Returns the opaque ref.
     */
    public function storeSource(int $accountId, int $siteId, int $generationId, string $bytes, string $mime): StoredMedia
    {
        return $this->put($accountId, $siteId, $generationId, self::KIND_SOURCE, $bytes, $mime);
    }

    /**
     * Store the generated RESULT image for a generation. Called only AFTER a
     * successful model call and BEFORE the charge — no charge without a stored result.
     */
    public function storeResult(int $accountId, int $siteId, int $generationId, string $bytes, string $mime): StoredMedia
    {
        return $this->put($accountId, $siteId, $generationId, self::KIND_RESULT, $bytes, $mime);
    }

    /**
     * Store the optional PRIVATE reference upload for a banner generation attempt.
     */
    public function storeBannerSource(int $accountId, int $siteId, int $bannerAssetId, string $bytes, string $mime): StoredMedia
    {
        return $this->putBanner($accountId, $siteId, $bannerAssetId, self::KIND_BANNER_SOURCE, $bytes, $mime, 'private');
    }

    /**
     * Store the generated banner RESULT as PUBLIC marketing media — the same creative is
     * served to every shopper by a stable public URL (not a per-shopper signed URL, which
     * would expire / vary per request). Called only after a successful model call and
     * BEFORE the charge — no charge without a stored result.
     */
    public function storeBannerResult(int $accountId, int $siteId, int $bannerAssetId, string $bytes, string $mime): StoredMedia
    {
        return $this->putBanner($accountId, $siteId, $bannerAssetId, self::KIND_BANNER, $bytes, $mime, 'public');
    }

    /**
     * Store a Super-Admin PLAYGROUND result (image or mp4) PRIVATE under a non-tenant path
     * (playground/{run}/...). Signed on demand for the admin view — never a tenant object.
     */
    public function storePlaygroundResult(int $runId, string $bytes, string $mime): StoredMedia
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;
        $path = implode('/', [self::PATH_PLAYGROUND, $runId, self::KIND_RESULT.'-'.Str::random(24).'.'.$extension]);

        $this->disk()->put($path, $bytes, ['visibility' => 'private']);

        return new StoredMedia($path, $mime, strlen($bytes));
    }

    /**
     * Store a storyboard FRAME image PRIVATE under a non-tenant path
     * (storyboard/{project}/frames/{frame}/...). Signed on demand for the admin view.
     */
    public function storeStoryboardFrame(int $projectId, int $frameId, string $bytes, string $mime): StoredMedia
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;
        $path = implode('/', [self::PATH_STORYBOARD, $projectId, self::PATH_FRAMES, $frameId, self::KIND_RESULT.'-'.Str::random(24).'.'.$extension]);

        $this->disk()->put($path, $bytes, ['visibility' => 'private']);

        return new StoredMedia($path, $mime, strlen($bytes));
    }

    /**
     * Store the COMBINED storyboard video (all frames stitched into one MP4) PRIVATE under
     * storyboard/{project}/final-{rand}.mp4. Signed on demand for the admin builder.
     */
    public function storeStoryboardVideo(int $projectId, string $bytes, string $mime = 'video/mp4'): StoredMedia
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;
        $path = implode('/', [self::PATH_STORYBOARD, $projectId, 'final-'.Str::random(24).'.'.$extension]);

        $this->disk()->put($path, $bytes, ['visibility' => 'private']);

        return new StoredMedia($path, $mime, strlen($bytes));
    }

    /** Read a stored object's raw bytes (used to feed source frames to ffmpeg). Null if absent. */
    public function get(?string $path): ?string
    {
        if ($path === null || $path === '' || ! $this->disk()->exists($path)) {
            return null;
        }

        return $this->disk()->get($path);
    }

    /**
     * A STABLE public URL for a public banner path (the widget embeds this directly). Uses
     * the disk's public/CDN url — no signing, no expiry. Returns null for a null/empty path.
     */
    public function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // A Railway Volume (local disk) is served by the app, not a CDN — a plain cacheable route.
        if ($this->isLocalDisk()) {
            return url(self::PUBLIC_PATH_PREFIX.$path);
        }

        return $this->disk()->url($path);
    }

    /**
     * A SHORT-lived signed URL for a stored path (the only thing the widget receives).
     * TTL comes from config. Returns null for a null/empty path (nothing stored yet).
     */
    public function signedUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // A local disk (Volume) can't mint object-store signed URLs — use an expiring signed route.
        if ($this->isLocalDisk()) {
            return URL::temporarySignedRoute(self::ROUTE_SIGNED, now()->addSeconds($this->ttlSeconds()), ['path' => $path]);
        }

        return $this->disk()->temporaryUrl($path, now()->addSeconds($this->ttlSeconds()));
    }

    /** Delete one stored object (used by the retention purge in Phase 9). */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->disk()->delete($path);
    }

    /**
     * Purge EVERY object belonging to a site — the whole accounts/{account}/sites/{site}/ prefix
     * (try-on sources + results, banner sources + results, preview snapshots). Called when a site
     * is deleted so no orphaned media is left on the bucket. The prefix leads with account_id then
     * site_id, so this can only ever remove the ONE site's objects — never another tenant's.
     */
    public function purgeSite(int $accountId, int $siteId): void
    {
        $this->disk()->deleteDirectory($this->sitePrefix($accountId, $siteId));
    }

    /** The per-site object prefix (account leads so it is never cross-tenant ambiguous). */
    public function sitePrefix(int $accountId, int $siteId): string
    {
        return implode('/', [self::PATH_ACCOUNTS, $accountId, self::PATH_SITES, $siteId]);
    }

    /** True if the object exists on the disk. */
    public function exists(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        return $this->disk()->exists($path);
    }

    /** The configured signed-URL lifetime in seconds. */
    public function ttlSeconds(): int
    {
        return (int) config(self::SIGNED_TTL_CONFIG_KEY);
    }

    /**
     * Write the bytes PRIVATE under the tenant/site/generation scoped path and return
     * the opaque ref. The filename carries a random token so a re-generation never
     * overwrites the previous attempt's object.
     */
    private function put(int $accountId, int $siteId, int $generationId, string $kind, string $bytes, string $mime): StoredMedia
    {
        $path = $this->buildPath($accountId, $siteId, $generationId, $kind, $mime);

        // Private visibility: a leaked path is useless without a fresh signed URL.
        $this->disk()->put($path, $bytes, ['visibility' => 'private']);

        return new StoredMedia($path, $mime, strlen($bytes));
    }

    /**
     * Write a banner object at the given visibility ('public' for the served creative,
     * 'private' for the reference upload) under the tenant/site/asset scoped banners path.
     */
    private function putBanner(int $accountId, int $siteId, int $bannerAssetId, string $kind, string $bytes, string $mime, string $visibility): StoredMedia
    {
        $path = $this->buildBannerPath($accountId, $siteId, $bannerAssetId, $kind, $mime);

        $this->disk()->put($path, $bytes, ['visibility' => $visibility]);

        return new StoredMedia($path, $mime, strlen($bytes));
    }

    /** Build the banner media path: accounts/{account}/sites/{site}/banners/{asset}/{kind}-{rand}.{ext}. */
    private function buildBannerPath(int $accountId, int $siteId, int $bannerAssetId, string $kind, string $mime): string
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;

        return implode('/', [
            self::PATH_ACCOUNTS,
            $accountId,
            self::PATH_SITES,
            $siteId,
            self::PATH_BANNERS,
            $bannerAssetId,
            $kind.'-'.Str::random(24).'.'.$extension,
        ]);
    }

    /** Build the deterministic-prefix, random-leaf media path. */
    private function buildPath(int $accountId, int $siteId, int $generationId, string $kind, string $mime): string
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;

        return implode('/', [
            self::PATH_ACCOUNTS,
            $accountId,
            self::PATH_SITES,
            $siteId,
            self::PATH_GENERATIONS,
            $generationId,
            $kind.'-'.Str::random(24).'.'.$extension,
        ]);
    }

    /** The configured media disk (s3/r2 or a local Volume in prod, faked in tests). */
    private function disk(): Filesystem
    {
        $disk = (string) config(self::DISK_CONFIG_KEY);

        if ($disk === '') {
            throw new RuntimeException(self::UNKNOWN_PATH_MESSAGE);
        }

        return Storage::disk($disk);
    }

    /** True when the media disk uses the local driver (a Railway Volume) — served by the app. */
    private function isLocalDisk(): bool
    {
        $disk = (string) config(self::DISK_CONFIG_KEY);

        return config('filesystems.disks.'.$disk.'.driver') === self::LOCAL_DRIVER;
    }
}
