<?php

namespace App\Domain\Scan;

use App\Domain\Products\PersistProduct;
use App\Domain\Products\ProductOrigin;
use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Map\MappedProduct;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Jobs\TenantAwareJob;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * ScanProductJob — orchestrate one PDP scan, tenant-bound.
 *
 * Extends TenantAwareJob (explicit constructor account_id, handle() binds the
 * tenant via Tenant::run which clears in finally — TS-TENANCY-001), and is unique
 * by the locked scan idempotency key scan:{account_id}:{site_id}:{sha1(url)} so a
 * double-dispatch for the same (account, site, url) collapses to one in-flight job.
 *
 * Flow: represent (fetch + clean) -> extract (resolve bag -> model call -> map) ->
 * persist Product(DRAFT) + variants + selectors. A scan NEVER auto-approves: it
 * lands status=draft; only the merchant's confirm() goes live. A typed
 * FetchException (bot-block / robots / empty render) persists a FAILED product with
 * the merchant-facing reason + the manual-entry path — never a 500, never silent.
 *
 * pdp-scanner produces; this job persists what PdpScanner returns. It does not
 * call OpenRouter and does not read the platform key.
 */
final class ScanProductJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const IDEMPOTENCY_PREFIX = 'scan';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly string $url,
    ) {
        parent::__construct($accountId);
        // Read the queue name from config, not the bare Q_SCANS constant: under
        // config:cache the define() in config/trayon.php never re-runs at runtime,
        // so the constant is undefined — but the cached config array still holds the
        // resolved value. (Construct-time crash otherwise; see TS-INFRA.)
        $this->onQueue((string) config('trayon.queues.scans'));
    }

    /**
     * The locked scan idempotency key (ARCHITECTURE.md):
     * scan:{account_id}:{site_id}:{sha1(url)}. ShouldBeUnique reads this.
     */
    public function uniqueId(): string
    {
        return self::scanKey($this->accountId, $this->siteId, $this->url);
    }

    /** Build the deterministic scan idempotency key. */
    public static function scanKey(int $accountId, int $siteId, string $url): string
    {
        return implode(':', [self::IDEMPOTENCY_PREFIX, $accountId, $siteId, sha1($url)]);
    }

    /** Runs with $this->accountId bound by TenantAwareJob::handle(). */
    protected function process(): void
    {
        $site = Site::query()->findOrFail($this->siteId);

        try {
            $scanner = app(PdpScanner::class);
            $representation = $scanner->represent($this->url);
            $mapped = $scanner->extract($representation, $site);

            $product = $this->persist($site, $mapped);

            // Keep a faithful copy of the fetched page so the visual placement picker
            // can render it later WITHOUT a fresh live fetch. Fail-soft by contract.
            app(PreviewSnapshotStore::class)->put($product, $representation->rawHtml);
        } catch (FetchException $e) {
            $this->persistFailure($site, $e);
        }
    }

    /**
     * Persist the scanned product as DRAFT (never auto-approved) + its variants,
     * through the SHARED PersistProduct action (the same writer the Shopify sync
     * uses). Re-scan of the same url refreshes the existing DRAFT/FAILED row; a
     * CONFIRMED row is left untouched, and a variant that vanished from the page is
     * ARCHIVED rather than deleted — so `generations.product_variant_id` survives.
     */
    private function persist(Site $site, MappedProduct $mapped): Product
    {
        return app(PersistProduct::class)
            ->persist($site, $mapped, ProductOrigin::scan($this->url))
            ->product;
    }

    /** Persist a FAILED product carrying the merchant-facing reason + manual path. */
    private function persistFailure(Site $site, FetchException $e): void
    {
        $product = $this->existingScannable($site)
            ?? new Product([
                'site_id' => $site->getKey(),
                'source_url' => $this->url,
                'source_url_hash' => sha1($this->url),
            ]);

        $product->fill([
            'site_id' => $site->getKey(),
            'source_url' => $this->url,
            'source_url_hash' => sha1($this->url),
            'warnings' => [
                'reason' => $e->reason,
                'message' => $e->merchantMessage(),
                'suggest_manual' => $e->suggestManual,
            ],
            'confidence' => 0.0,
        ]);

        // Reach FAILED via the guarded transition (draft->failed) when not new.
        if ($product->exists && $product->status === Product::STATUS_DRAFT) {
            $product->save();
            $product->markFailed();
        } else {
            $product->status = Product::STATUS_FAILED;
            $product->save();
        }
    }

    /**
     * The existing DRAFT/FAILED product for this url (re-scan target). A CONFIRMED
     * product is never overwritten by a queued re-scan.
     */
    private function existingScannable(Site $site): ?Product
    {
        return Product::query()
            ->where('site_id', $site->getKey())
            ->where('source_url_hash', sha1($this->url))
            ->whereIn('status', [Product::STATUS_DRAFT, Product::STATUS_FAILED])
            ->first();
    }
}
