<?php

namespace App\Domain\Ai;

use App\Models\StylePreset;

/**
 * StylePresetApplier — swaps an operation's user prompt for an APPROVED style preset's prompt.
 *
 * The single seam every generation path (Try-On, Banners, Image Studio) uses to apply a
 * merchant/shopper-chosen style. FAIL-OPEN by design: a null / unknown / inactive / unapproved /
 * mismatched-operation preset id returns the config UNCHANGED (the default look) — a stale or
 * hostile style id can never break or hijack a generation, it just doesn't apply. Money-safety is
 * untouched: only the prompt changes; the operation's model/quality/cost + the credit gate stay.
 */
final class StylePresetApplier
{
    /**
     * Return $config with its user prompt replaced by the preset's, or unchanged when the preset
     * cannot be safely applied. The preset MUST target this exact operation (so a try-on style can
     * never be applied to a banner op, etc.).
     */
    public function applyTo(OperationConfig $config, ?int $presetId): OperationConfig
    {
        if ($presetId === null) {
            return $config;
        }

        $preset = StylePreset::query()
            ->whereKey($presetId)
            ->where('status', StylePreset::STATUS_APPROVED)
            ->where('is_active', true)
            ->where('operation_key', $config->operationKey)
            ->first();

        if ($preset === null || trim((string) $preset->user_prompt) === '') {
            return $config;
        }

        return $config->withUserPrompt((string) $preset->user_prompt);
    }
}
