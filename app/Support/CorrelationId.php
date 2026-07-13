<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * CorrelationId — one opaque id minted at an inbound edge (webhook receipt, OAuth
 * callback, bulk-batch start) and carried through every log line and job it spawns,
 * so a failure spanning Shopify -> queue -> AI provider is traceable end to end.
 */
final class CorrelationId
{
    // === CONSTANTS ===
    private const PREFIX = 'cor_';

    private const RANDOM_LENGTH = 26;

    public static function mint(): string
    {
        return self::PREFIX.Str::lower(Str::random(self::RANDOM_LENGTH));
    }
}
