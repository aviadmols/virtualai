<?php

use App\Models\AiOperation;
use App\Models\Prompt;
use Illuminate\Database\Migrations\Migration;

/**
 * Try-on prompt enrichment (Shopify Phase 3).
 *
 * Until now only name / type / variant / height reached the model: description,
 * materials, the option map and the measured dimensions were persisted at scan time and
 * then never used. Shopify-sourced products carry all of it verbatim from the merchant's
 * store, so the prompt can finally state what the item is made of and how it is cut.
 *
 * ProductFacts composes {{product_details}} at generation time and it expands to NOTHING
 * when a product has none of those facts — so appending the placeholder is safe for
 * existing scanned products.
 *
 * Only PLATFORM prompts (account_id IS NULL) are touched, and only when the placeholder
 * is absent — a merchant/admin-edited prompt is never clobbered.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const PLACEHOLDER = '{{product_details}}';

    // The framing clause must stay LAST (it governs the output image geometry), so the
    // details clause is inserted immediately before it when present.
    private const FRAMING_NEEDLE = 'Return the image at the SAME orientation';

    public function up(): void
    {
        $prompts = Prompt::query()
            ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->whereNull('account_id')
            ->whereNull('site_id')
            ->get();

        foreach ($prompts as $prompt) {
            $user = (string) $prompt->user_prompt;

            if ($user === '' || str_contains($user, self::PLACEHOLDER)) {
                continue; // already enriched (or an empty template) — never double-append
            }

            $prompt->user_prompt = $this->insert($user);
            $prompt->save();
        }
    }

    public function down(): void
    {
        $prompts = Prompt::query()
            ->where('operation_key', AiOperation::KEY_TRY_ON_GENERATION)
            ->whereNull('account_id')
            ->whereNull('site_id')
            ->get();

        foreach ($prompts as $prompt) {
            $user = (string) $prompt->user_prompt;

            if (! str_contains($user, self::PLACEHOLDER)) {
                continue;
            }

            $prompt->user_prompt = trim(str_replace(self::PLACEHOLDER.' ', '', $user));
            $prompt->save();
        }
    }

    /** Put the details clause before the framing clause; else append it at the end. */
    private function insert(string $user): string
    {
        $position = strpos($user, self::FRAMING_NEEDLE);

        if ($position === false) {
            return rtrim($user).' '.self::PLACEHOLDER;
        }

        return rtrim(substr($user, 0, $position)).' '.self::PLACEHOLDER.' '.substr($user, $position);
    }
};
