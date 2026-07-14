<?php

namespace App\Domain\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 *
 * EVERY WRITE IS VERIFIED, NEVER MERELY ATTEMPTED. Every disk is configured `throw => false`,
 * so a failed put() returns FALSE instead of raising. Ignoring that boolean handed callers a
 * path pointing at nothing — a "stored" result that could still be charged, and a Shopify
 * snapshot that licensed the deletion of an original we did not actually hold. So a write goes
 * through ONE gateway (write()) that checks the boolean AND reads the object back; anything else
 * throws MediaWriteException. The money rails already store BEFORE they charge, so a typed write
 * failure releases the hold and writes no charge row.
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

    private const PATH_PRODUCT_ASSETS = 'product-assets';

    // Our own copy of a Shopify product's ORIGINAL gallery, taken before the first destructive
    // push. Shopify drops the CDN bytes when a media object is deleted, so THESE bytes are the
    // only thing that makes "Undo / restore original images" real. Never purged while the
    // product exists (they die with the site prefix, i.e. only when the product is gone too).
    private const PATH_SHOPIFY_SNAPSHOTS = 'shopify-snapshots';

    // The two object kinds a generation stores.
    public const KIND_SOURCE = 'source';

    public const KIND_RESULT = 'result';

    // Banner object kinds. The generated banner is PUBLIC marketing media (shown to every
    // shopper by a stable URL); the optional reference upload stays PRIVATE.
    public const KIND_BANNER = 'banner';

    public const KIND_BANNER_SOURCE = 'banner-source';

    // The bulk product-image transform result (Product Image Studio). PRIVATE: the merchant
    // panel receives a short-lived signed URL, and Phase 5 reads the BYTES to push them to
    // Shopify — the bucket itself never goes public.
    public const KIND_PRODUCT_ASSET = 'product-asset';

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

    // The two visibilities an object is written at. Everything is PRIVATE except the served
    // banner creative (one stable public URL for every shopper).
    private const VISIBILITY_PRIVATE = 'private';

    private const VISIBILITY_PUBLIC = 'public';

    private const VISIBILITY_KEY = 'visibility';

    // An object that reads back at zero bytes is not an object — it is a lie with a path.
    private const MIN_VERIFIED_BYTES = 1;

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
        return $this->putBanner($accountId, $siteId, $bannerAssetId, self::KIND_BANNER_SOURCE, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Store the generated banner RESULT as PUBLIC marketing media — the same creative is
     * served to every shopper by a stable public URL (not a per-shopper signed URL, which
     * would expire / vary per request). Called only after a successful model call and
     * BEFORE the charge — no charge without a stored result.
     */
    public function storeBannerResult(int $accountId, int $siteId, int $bannerAssetId, string $bytes, string $mime): StoredMedia
    {
        return $this->putBanner($accountId, $siteId, $bannerAssetId, self::KIND_BANNER, $bytes, $mime, self::VISIBILITY_PUBLIC);
    }

    /**
     * Store the generated PRODUCT ASSET (a packshot / on-model render) PRIVATE under the
     * tenant/site/asset path. Called only AFTER a successful provider call and BEFORE the
     * charge — no charge without a stored result.
     */
    public function storeProductAsset(int $accountId, int $siteId, int $productAssetId, string $bytes, string $mime): StoredMedia
    {
        $path = implode('/', [
            self::PATH_ACCOUNTS,
            $accountId,
            self::PATH_SITES,
            $siteId,
            self::PATH_PRODUCT_ASSETS,
            $productAssetId,
            self::KIND_RESULT.'-'.Str::random(24).'.'.(self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION),
        ]);

        return $this->write($path, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Store ONE original Shopify gallery image PRIVATE under the tenant/site/product snapshot
     * path — the byte-level backup that makes Undo honest. Called BEFORE any destructive push;
     * if this throws, the push is REFUSED (fail closed), because an undo we cannot honour is
     * worse than no undo at all. The write is VERIFIED (put() + readback), so a snapshot can
     * never license the deletion of an original whose bytes never landed.
     */
    public function storeShopifySnapshot(int $accountId, int $siteId, int $productId, string $bytes, string $mime): StoredMedia
    {
        $path = implode('/', [
            self::PATH_ACCOUNTS,
            $accountId,
            self::PATH_SITES,
            $siteId,
            self::PATH_SHOPIFY_SNAPSHOTS,
            $productId,
            'original-'.Str::random(24).'.'.(self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION),
        ]);

        return $this->write($path, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Store a Super-Admin PLAYGROUND result (image or mp4) PRIVATE under a non-tenant path
     * (playground/{run}/...). Signed on demand for the admin view — never a tenant object.
     */
    public function storePlaygroundResult(int $runId, string $bytes, string $mime): StoredMedia
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;
        $path = implode('/', [self::PATH_PLAYGROUND, $runId, self::KIND_RESULT.'-'.Str::random(24).'.'.$extension]);

        return $this->write($path, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Store a storyboard FRAME image PRIVATE under a non-tenant path
     * (storyboard/{project}/frames/{frame}/...). Signed on demand for the admin view.
     */
    public function storeStoryboardFrame(int $projectId, int $frameId, string $bytes, string $mime): StoredMedia
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;
        $path = implode('/', [self::PATH_STORYBOARD, $projectId, self::PATH_FRAMES, $frameId, self::KIND_RESULT.'-'.Str::random(24).'.'.$extension]);

        return $this->write($path, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Store the COMBINED storyboard video (all frames stitched into one MP4) PRIVATE under
     * storyboard/{project}/final-{rand}.mp4. Signed on demand for the admin builder.
     */
    public function storeStoryboardVideo(int $projectId, string $bytes, string $mime = 'video/mp4'): StoredMedia
    {
        $extension = self::EXTENSIONS[strtolower($mime)] ?? self::DEFAULT_EXTENSION;
        $path = implode('/', [self::PATH_STORYBOARD, $projectId, 'final-'.Str::random(24).'.'.$extension]);

        return $this->write($path, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Stream a stored object's BYTES back through the app (the same-origin door).
     *
     * The signed URL points at the media origin (S3/R2/CDN), which is a DIFFERENT origin from
     * the storefront and answers no CORS — so a page can render it in an <img> but can never
     * fetch() it into a Blob. navigator.share({files}) needs a File, i.e. the bytes. This is the
     * one read that hands them over, through an app route that already carries the caller's CORS.
     *
     * It authorizes NOTHING: ownership is the caller's business (the widget door resolves the
     * generation against site + anon_token + the bound account first). A missing/empty path or a
     * purged object returns NULL so the caller answers 404 rather than raising a 500.
     */
    public function stream(?string $path, array $headers = []): ?StreamedResponse
    {
        if (! $this->exists($path)) {
            return null;
        }

        /** @var FilesystemAdapter $disk */
        $disk = $this->disk();

        return $disk->response((string) $path, headers: $headers);
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

    /**
     * True when the object is REALLY there and REALLY has bytes.
     *
     * The difference from exists() is the point: a zero-byte object exists. This is the predicate
     * anything irreversible must hang on — a Shopify original is only allowed to be deleted from
     * a live storefront once THIS says we can hand the bytes back.
     */
    public function isReadable(?string $path): bool
    {
        return $this->byteSize($path) >= self::MIN_VERIFIED_BYTES;
    }

    /** The stored object's size in bytes; 0 when it is absent (or unreadable). */
    public function byteSize(?string $path): int
    {
        if ($path === null || $path === '' || ! $this->disk()->exists($path)) {
            return 0;
        }

        return max(0, (int) $this->disk()->size($path));
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
        return $this->write($path, $bytes, $mime, self::VISIBILITY_PRIVATE);
    }

    /**
     * Write a banner object at the given visibility ('public' for the served creative,
     * 'private' for the reference upload) under the tenant/site/asset scoped banners path.
     */
    private function putBanner(int $accountId, int $siteId, int $bannerAssetId, string $kind, string $bytes, string $mime, string $visibility): StoredMedia
    {
        $path = $this->buildBannerPath($accountId, $siteId, $bannerAssetId, $kind, $mime);

        return $this->write($path, $bytes, $mime, $visibility);
    }

    /**
     * THE ONE WRITE GATEWAY — and the ONE place a StoredMedia is minted.
     *
     * A write is VERIFIED, never merely attempted:
     *   1. put() returns FALSE on a failed write (every disk is `throw => false`) — a returned
     *      path would then point at nothing, and the caller would believe it;
     *   2. the object is READ BACK, and its size must equal EXACTLY the number of bytes we
     *      handed the disk.
     *
     * VERIFY THE PREDICATE YOU CARE ABOUT, NOT A PROXY FOR IT. The readback used to ask only
     * "is it at least 1 byte?" — a question a TRUNCATED object answers yes to. On a local /
     * volume disk (MEDIA_DISK=volume, a Railway Volume) Flysystem writes with file_put_contents,
     * which on a FULL disk performs a SHORT write and returns a byte COUNT, not false: put() says
     * yes, the object exists, it is non-empty, and it is not the image. That snapshot would then
     * be stamped CAPTURED and would license the deletion of a merchant's live original — leaving
     * us holding 1 byte of it. "The volume is full" must never mean "the original is gone".
     *
     * Any check failing is a typed MediaWriteException. The rails that store before they charge
     * (try-on, banner, product image) turn it into a released hold and NO charge row; the Shopify
     * snapshot turns it into a REFUSED destructive push.
     */
    private function write(string $path, string $bytes, string $mime, string $visibility): StoredMedia
    {
        $expected = strlen($bytes);

        if ($this->disk()->put($path, $bytes, [self::VISIBILITY_KEY => $visibility]) === false) {
            throw MediaWriteException::rejected($path, $expected);
        }

        $stored = $this->byteSize($path);

        // OUR bytes, ALL of them. A short write and an empty object are the same lie.
        if ($stored !== $expected || $stored < self::MIN_VERIFIED_BYTES) {
            throw MediaWriteException::unverified($path, $expected, $stored);
        }

        return new StoredMedia($path, $mime, $stored);
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
