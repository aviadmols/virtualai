<?php

namespace App\Domain\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
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

    // Path segments — account leads so an object is never cross-tenant ambiguous.
    private const PATH_ACCOUNTS = 'accounts';
    private const PATH_SITES = 'sites';
    private const PATH_GENERATIONS = 'generations';

    // The two object kinds a generation stores.
    public const KIND_SOURCE = 'source';
    public const KIND_RESULT = 'result';

    // mime -> extension (the disk key carries a sane extension for the CDN).
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
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
     * A SHORT-lived signed URL for a stored path (the only thing the widget receives).
     * TTL comes from config. Returns null for a null/empty path (nothing stored yet).
     */
    public function signedUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
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

    /** The configured media disk (s3/r2 in prod, faked in tests). */
    private function disk(): Filesystem
    {
        $disk = (string) config(self::DISK_CONFIG_KEY);

        if ($disk === '') {
            throw new RuntimeException(self::UNKNOWN_PATH_MESSAGE);
        }

        return Storage::disk($disk);
    }
}
