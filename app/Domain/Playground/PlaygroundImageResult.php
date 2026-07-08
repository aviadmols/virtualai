<?php

namespace App\Domain\Playground;

use App\Domain\Ai\ParsedCost;

/**
 * PlaygroundImageResult — the outcome of a playground IMAGE generation: the result bytes + mime,
 * the parsed cost (inline for OpenRouter, the flat-rate hint for BytePlus/xAI), and the model the
 * provider actually ran.
 */
final readonly class PlaygroundImageResult
{
    public function __construct(
        public string $imageBytes,
        public string $mimeType,
        public ParsedCost $cost,
        public string $modelUsed,
    ) {}
}
