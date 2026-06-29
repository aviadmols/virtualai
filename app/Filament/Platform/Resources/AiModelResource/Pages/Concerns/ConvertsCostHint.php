<?php

namespace App\Filament\Platform\Resources\AiModelResource\Pages\Concerns;

use App\Domain\Credits\CreditMath;

/**
 * Shared form-data mutation for the AI model pages: the cost hint is ENTERED in USD
 * (the `cost_hint_usd` virtual field) and STORED as integer micro-USD (the money
 * unit everywhere). Both Create and Edit fold the USD input into the persisted
 * `cost_hint_micro_usd` column with one conversion (CreditMath::usdToMicro), so the
 * money column is never a float and the conversion lives in exactly one place.
 */
trait ConvertsCostHint
{
    // === CONSTANTS ===
    private const INPUT_KEY = 'cost_hint_usd';
    private const COLUMN_KEY = 'cost_hint_micro_usd';

    /**
     * Replace the USD input with the integer micro-USD column before persisting.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function foldCostHint(array $data): array
    {
        $usd = $data[self::INPUT_KEY] ?? null;
        unset($data[self::INPUT_KEY]);

        $data[self::COLUMN_KEY] = $usd !== null && $usd !== ''
            ? CreditMath::usdToMicro((float) $usd)
            : null;

        return $data;
    }
}
