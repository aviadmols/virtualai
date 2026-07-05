<?php

namespace App\Domain\Scan\Preview;

use App\Models\Product;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PreviewSnapshotStore — durable, tenant-scoped storage of a scanned page's RAW HTML.
 *
 * The visual placement picker needs a faithful, styled copy of the merchant's page to
 * render + let them click an element. Re-fetching live at click-time is slow and can
 * fail (headless timeout, bot-block) — so we capture the raw HTML ONCE at scan time
 * and read it back here. One snapshot per product (latest scan wins), written PRIVATE
 * to the media disk under the account/site path so a leaked path is useless and no
 * cross-tenant read is possible (the path is derived from the product's OWN account).
 *
 * Fail-soft on both sides: a write never breaks a scan, a read never 500s the picker.
 */
final class PreviewSnapshotStore
{
    // === CONSTANTS ===
    private const DISK_CONFIG_KEY = 'trayon.media.disk';

    private const PATH_ACCOUNTS = 'accounts';
    private const PATH_SITES = 'sites';
    private const PATH_PREVIEWS = 'previews';
    private const EXTENSION = 'html';

    /** Store the scanned page's raw HTML for this product. Returns false (logged) on any failure. */
    public function put(Product $product, string $rawHtml): bool
    {
        if ($rawHtml === '') {
            return false;
        }

        try {
            $this->disk()->put($this->path($product), $rawHtml, ['visibility' => 'private']);

            return true;
        } catch (\Throwable $e) {
            Log::warning('preview snapshot write failed', [
                'product_id' => $product->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** The stored raw HTML for this product, or null when there is no snapshot / read fails. */
    public function get(Product $product): ?string
    {
        try {
            $path = $this->path($product);

            if (! $this->disk()->exists($path)) {
                return null;
            }

            $html = $this->disk()->get($path);

            return is_string($html) && $html !== '' ? $html : null;
        } catch (\Throwable $e) {
            Log::warning('preview snapshot read failed', [
                'product_id' => $product->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function has(Product $product): bool
    {
        return $this->get($product) !== null;
    }

    /**
     * The deterministic, account-scoped snapshot path. Derived from the product's OWN
     * account_id/site_id (never a request value), so it can only ever address the
     * merchant's own tenant space.
     */
    private function path(Product $product): string
    {
        return implode('/', [
            self::PATH_ACCOUNTS,
            (int) $product->account_id,
            self::PATH_SITES,
            (int) $product->site_id,
            self::PATH_PREVIEWS,
            $product->source_url_hash.'.'.self::EXTENSION,
        ]);
    }

    /** The configured media disk (s3/r2 in prod, faked in tests). */
    private function disk(): Filesystem
    {
        return Storage::disk((string) config(self::DISK_CONFIG_KEY));
    }
}
