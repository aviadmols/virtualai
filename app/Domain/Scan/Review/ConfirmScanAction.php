<?php

namespace App\Domain\Scan\Review;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * ConfirmScanAction — the confirm/correct WRITE path. Applies the merchant's
 * corrections (field values + chosen selectors + variant edits) onto Product /
 * ProductVariant and confirms the scan (draft -> confirmed) — the ONLY path a
 * product goes live.
 *
 * THE GATE IS ENFORCED HERE, server-side. Before any write, it re-evaluates the
 * ConfirmGate over the persisted scan with the merchant's reviewed-row keys; if a
 * low / not_detected row is still unreviewed it throws — so a crafted request can
 * never bypass the UI and confirm an unreviewed scan. The single ConfirmGate source
 * the UI also reads means the UI's disabled button and this guard never disagree.
 *
 * Tenant-safe: runs inside Tenant::run($account, …) so BelongsToAccount scopes the
 * Product/variant reads + the account_id auto-fill; account_id is explicit, never
 * ambient. No withoutGlobalScopes.
 */
final readonly class ConfirmScanAction
{
    /**
     * Confirm a draft product with the merchant's corrections.
     *
     * @throws ScanConfirmBlockedException when a blocking row is still unreviewed
     * @throws \RuntimeException when the product is not in a confirmable state (guarded transition)
     */
    public function confirm(Product $product, ConfirmScanInput $input): Product
    {
        return Tenant::run($product->account_id, function () use ($product, $input): Product {
            // Re-load within the tenant scope so isolation + freshness are guaranteed.
            $product = Product::query()->with('variants')->findOrFail($product->getKey());

            $this->assertGateOpen($product, $input);

            return DB::transaction(function () use ($product, $input): Product {
                $this->applyFieldCorrections($product, $input);
                $this->applySelectorChoices($product, $input);
                $this->syncVariants($product, $input);

                // confirm() runs the guarded draft -> confirmed transition + saves.
                $product->confirm();

                return $product->fresh('variants');
            });
        });
    }

    /**
     * The server-side no-auto-approve gate: re-evaluate the ConfirmGate over the
     * persisted scan with the merchant's reviewed keys. Throws if still blocked.
     */
    private function assertGateOpen(Product $product, ConfirmScanInput $input): void
    {
        $review = ScanReview::fromProduct($product);
        $gate = ConfirmGate::evaluate($review->rows(), $input->reviewedKeys);

        if (! $gate->canConfirm) {
            throw ScanConfirmBlockedException::from($gate);
        }
    }

    /** Apply the merchant's corrected product columns (writable columns only). */
    private function applyFieldCorrections(Product $product, ConfirmScanInput $input): void
    {
        $attributes = $input->productAttributes();

        if ($attributes !== []) {
            $product->fill($attributes);
        }
    }

    /**
     * Merge the merchant's chosen selectors into detected_selectors, marking each
     * chosen role confirmed so widget-embed reads only confirmed selectors. A manual
     * override replaces the primary and keeps the detected one in the fallback chain.
     */
    private function applySelectorChoices(Product $product, ConfirmScanInput $input): void
    {
        if ($input->selectors === []) {
            return;
        }

        $detected = $product->detected_selectors ?? [];

        foreach ($input->selectors as $role => $chosen) {
            $existing = $detected[$role] ?? [];
            $previousPrimary = $existing['primary'] ?? null;

            $fallback = $existing['fallback_chain'] ?? [];
            if ($previousPrimary !== null && $previousPrimary !== $chosen && ! in_array($previousPrimary, $fallback, true)) {
                array_unshift($fallback, $previousPrimary);
            }

            $detected[$role] = array_merge($existing, [
                'primary' => $chosen,
                'fallback_chain' => array_values(array_filter($fallback, fn ($s) => $s !== $chosen)),
                'confirmed' => true,
            ]);
        }

        $product->detected_selectors = $detected;
    }

    /**
     * Sync the merchant's corrected variant rows. Existing rows are updated in the
     * tenant scope (BelongsToAccount); new rows get account_id auto-filled. Rows are
     * matched by id when present; account_id is never taken from the payload.
     */
    private function syncVariants(Product $product, ConfirmScanInput $input): void
    {
        foreach ($input->variants as $row) {
            $attributes = array_intersect_key($row, array_flip(self::WRITABLE_VARIANT_COLUMNS));

            $id = $row['id'] ?? null;

            if ($id !== null) {
                $variant = $product->variants->firstWhere('id', $id);
                if ($variant !== null) {
                    $variant->fill($attributes)->save();

                    continue;
                }
            }

            $product->variants()->create(array_merge($attributes, [
                'account_id' => Tenant::id(),
            ]));
        }
    }

    // === WRITABLE VARIANT COLUMNS (merchant-correctable on confirm) ===
    public const WRITABLE_VARIANT_COLUMNS = [
        'options',
        'price_minor',
        'image_url',
        'sku',
        'available',
    ];
}
