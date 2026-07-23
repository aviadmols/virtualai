<?php

namespace App\Http\Widget\Resources;

use App\Domain\Media\MediaStorage;
use App\Models\Generation;

/**
 * GenerationPayload — the PUBLIC shape of a try-on for the widget's poll + gallery.
 *
 * Carries the status, and ONLY when succeeded a SHORT-lived SIGNED result URL minted on
 * demand (never the opaque disk path, never a public URL). The failure_code is surfaced
 * so the widget can render the right screen, but the source/result PATHS, the cost, the
 * charge ledger id, and any tenant internal are NEVER serialized.
 *
 * It also carries the look's PRODUCT + VARIANT (secret-free, the same public shape the
 * bootstrap product uses), so the gallery can switch the shown product name/price and the
 * add-to-cart target when the shopper taps a past look of a different product. Product +
 * variant are read through the account-scoped relations — eager-load them at the call site.
 */
final class GenerationPayload
{
    public static function make(Generation $generation, MediaStorage $media): array
    {
        $succeeded = $generation->isSucceeded();

        return [
            'id' => (int) $generation->getKey(),
            'status' => $generation->status,
            'failure_code' => $generation->failure_code,
            // A signed, expiring URL only once the result is stored + the row succeeded.
            'result_url' => $succeeded ? $media->signedUrl($generation->result_image_path) : null,
            'created_at' => optional($generation->created_at)->toIso8601String(),
            // The product this look was generated for (name + price for the header), and the
            // exact variant (add-to-cart target). Null when the row no longer links them.
            'product' => self::product($generation),
            'variant' => self::variant($generation),
        ];
    }

    /** @return array{id:int,name:?string,price_minor:?int,currency:?string}|null */
    private static function product(Generation $generation): ?array
    {
        $product = $generation->product;

        if ($product === null) {
            return null;
        }

        return [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'price_minor' => $product->price_minor,
            'currency' => $product->currency,
        ];
    }

    /**
     * The look's variant in the shared public shape. Null for a single-SKU product or a
     * variant row that no longer exists — the widget then falls back to the product price
     * and the option snapshot the generation stored.
     */
    private static function variant(Generation $generation): ?array
    {
        $variant = $generation->variant;

        return $variant !== null ? VariantPayload::make($variant) : null;
    }
}
