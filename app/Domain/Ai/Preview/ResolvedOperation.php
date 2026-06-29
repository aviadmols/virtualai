<?php

namespace App\Domain\Ai\Preview;

use App\Domain\Ai\OperationConfig;
use App\Models\Prompt;

/**
 * ResolvedOperation — the internal output of the resolver's single shared core.
 *
 * Carries everything both for() and preview() need: the resolved OperationConfig
 * (what for() returns), the winning Prompt row (its id + scope feed the preview),
 * and the two resolution traces. It exists only so the model + prompt winner are
 * decided exactly once — for() and preview() can never drift.
 */
final readonly class ResolvedOperation
{
    public function __construct(
        public OperationConfig $config,
        public Prompt $winningPrompt,
        public ResolutionTrace $modelTrace,
        public ResolutionTrace $promptTrace,
    ) {}
}
