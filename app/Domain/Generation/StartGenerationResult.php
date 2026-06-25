<?php

namespace App\Domain\Generation;

use App\Models\Generation;

/**
 * StartGenerationResult — the handle StartGeneration returns to the (Phase-7) widget
 * API. The widget polls the generation by id for its status + (when succeeded) the
 * signed result URL.
 *
 * `reused` is true when the deterministic idempotency key matched an existing
 * generation (a double-clicked button): the SAME generation is returned and NO new
 * job is dispatched — the four-layer wall, collapsed at the entry point.
 */
final readonly class StartGenerationResult
{
    public function __construct(
        public int $generationId,
        public string $status,
        public bool $reused,
    ) {}

    public static function fromGeneration(Generation $generation, bool $reused): self
    {
        return new self($generation->getKey(), $generation->status, $reused);
    }
}
