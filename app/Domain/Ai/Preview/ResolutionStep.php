<?php

namespace App\Domain\Ai\Preview;

/**
 * ResolutionStep — one line of the resolution trace.
 *
 * Records, for a single level of a precedence walk (a prompt leg
 * site/account/product_type/global, or a model-resolution step), WHAT was
 * considered and the OUTCOME. The trace is purely descriptive: it never makes a
 * call and never writes — it explains "what won and why" to the prompts editor.
 */
final readonly class ResolutionStep
{
    // === CONSTANTS ===
    // Outcome of considering a level. The winner is the first WON in the walk;
    // everything before it is CONSIDERED-but-not-matched; everything after is
    // NOT_REACHED (short-circuited, never queried by the real resolver).
    public const OUTCOME_WON = 'won';
    public const OUTCOME_NO_MATCH = 'no_match';
    public const OUTCOME_NOT_REACHED = 'not_reached';
    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * @param  string  $level  the precedence level key (e.g. Prompt::SCOPE_SITE, or a model step)
     * @param  string  $outcome  one of the OUTCOME_* constants
     * @param  string  $detail  a human-readable, NON-SENSITIVE explanation (no secrets/keys)
     * @param  array<string,mixed>  $considered  the constraint values examined at this level
     * @param  int|string|null  $winningId  the id of the row that supplied the winner (prompt id / model id), when OUTCOME_WON
     */
    public function __construct(
        public string $level,
        public string $outcome,
        public string $detail,
        public array $considered = [],
        public int|string|null $winningId = null,
    ) {}

    public function won(): bool
    {
        return $this->outcome === self::OUTCOME_WON;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'outcome' => $this->outcome,
            'detail' => $this->detail,
            'considered' => $this->considered,
            'winning_id' => $this->winningId,
        ];
    }
}
