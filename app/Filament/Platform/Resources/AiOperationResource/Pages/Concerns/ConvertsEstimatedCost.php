<?php

namespace App\Filament\Platform\Resources\AiOperationResource\Pages\Concerns;

use App\Domain\Credits\CreditMath;

/**
 * Shared form-data mutation for the AI operation pages: the estimated cost is
 * ENTERED in USD (the `estimated_cost_usd` virtual field) and STORED as integer
 * micro-USD (the money unit everywhere). Both Create and Edit fold the USD input
 * into `estimated_cost_micro_usd` with one conversion (CreditMath::usdToMicro), so
 * the money column is never a float and the conversion lives in one place.
 */
trait ConvertsEstimatedCost
{
    // === CONSTANTS ===
    private const INPUT_KEY = 'estimated_cost_usd';
    private const COLUMN_KEY = 'estimated_cost_micro_usd';

    /**
     * Replace the USD input with the integer micro-USD column before persisting.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function foldEstimatedCost(array $data): array
    {
        $usd = $data[self::INPUT_KEY] ?? null;
        unset($data[self::INPUT_KEY]);

        $data[self::COLUMN_KEY] = $usd !== null && $usd !== ''
            ? CreditMath::usdToMicro((float) $usd)
            : null;

        return $data;
    }
}
