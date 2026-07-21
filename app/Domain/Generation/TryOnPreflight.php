<?php

namespace App\Domain\Generation;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\ProductScanCaller;
use App\Models\AiOperation;
use App\Models\Site;
use Throwable;

/**
 * TryOnPreflight (Slice E) — the vision pass that runs BEFORE a try-on render.
 *
 * It answers one question and produces one improvement:
 *   - is the shopper photo usable at all (a person visible, adequately lit, not a screenshot of
 *     something else)? An unusable photo is REJECTED here — the try-on is cancelled before any
 *     reserve/charge, so the shopper never pays for (and never sees) a doomed render;
 *   - when usable, the model returns short REFINEMENT guidance (pose/lighting/framing notes) that
 *     is appended to the try-on prompt for a more faithful result.
 *
 * FAIL-OPEN is the law here: this is an ENHANCEMENT, not a new dependency. If the operation is not
 * configured/active, the provider errors, or the output is not schema-valid, run() returns
 * pass() — the try-on proceeds EXACTLY as it did before Slice E existed. It reuses the
 * operation-agnostic ProductScanCaller (image → strict JSON), so no new provider code.
 */
final class TryOnPreflight
{
    // === CONSTANTS ===
    private const OPERATION_KEY = AiOperation::KEY_TRY_ON_PREFLIGHT;

    // The strict-JSON verdict keys (mirror the seeded input_schema).
    private const KEY_USABLE = 'usable';

    private const KEY_REASON = 'reason';

    private const KEY_REFINEMENT = 'prompt_refinement';

    // The reason recorded (for the merchant's activity log) when the model rejects without saying why.
    private const DEFAULT_REJECT_REASON = 'The photo could not be used for a try-on.';

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly ProductScanCaller $caller,
    ) {}

    /**
     * Judge the shopper photo + (when usable) return prompt refinement. The shopper photo is the
     * FIRST image, the product the second — the seeded prompt refers to them in that order.
     *
     * @param  array<string,string|int|float|null>  $vars  prompt placeholders (product_name/type/…)
     */
    public function run(Site $site, ?string $productType, ImagePayload $shopper, ImagePayload $productImage, array $vars): PreflightResult
    {
        try {
            $config = $this->resolver->for(self::OPERATION_KEY, $site, $productType);

            $json = $this->caller->extract($config, $vars, [$shopper, $productImage])->json;

            // Only an explicit `usable: false` rejects; anything else (missing key, malformed) is
            // treated as usable — the fail-open bias never blocks a try-on on a soft signal.
            if (($json[self::KEY_USABLE] ?? true) === false) {
                $reason = trim((string) ($json[self::KEY_REASON] ?? ''));

                return PreflightResult::reject($reason !== '' ? $reason : self::DEFAULT_REJECT_REASON);
            }

            return PreflightResult::pass((string) ($json[self::KEY_REFINEMENT] ?? ''));
        } catch (Throwable) {
            // Missing/inactive operation, provider failure, non-schema output — never block a try-on.
            return PreflightResult::pass();
        }
    }
}
