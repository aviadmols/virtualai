<?php

namespace App\Domain\Ai\Preview;

/**
 * ResolutionTrace — an ordered walk of precedence levels and which one won.
 *
 * Wraps the per-level ResolutionStep rows for one decision (the prompt walk, or
 * the model walk). The winner is the first step whose outcome is WON; the
 * winningLevel exposes which precedence level supplied it. Purely descriptive —
 * built by the resolver's shared trace cores, never by a call.
 */
final readonly class ResolutionTrace
{
    /**
     * @param  string  $kind  what this trace resolves ('prompt' | 'model' | 'fallback')
     * @param  list<ResolutionStep>  $steps  the levels in precedence order (most specific first)
     */
    public function __construct(
        public string $kind,
        public array $steps,
    ) {}

    /** The step that supplied the winner, or null if nothing matched. */
    public function winningStep(): ?ResolutionStep
    {
        foreach ($this->steps as $step) {
            if ($step->won()) {
                return $step;
            }
        }

        return null;
    }

    /** The precedence level that supplied the winner (e.g. 'site', 'global'), or null. */
    public function winningLevel(): ?string
    {
        return $this->winningStep()?->level;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'winning_level' => $this->winningLevel(),
            'steps' => array_map(static fn (ResolutionStep $s): array => $s->toArray(), $this->steps),
        ];
    }
}
