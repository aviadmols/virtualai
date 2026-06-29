<?php

namespace App\Domain\Scan\Review;

/**
 * ConfirmGate — the SINGLE no-auto-approve predicate. A product MAY be confirmed
 * only when every BLOCKING row (a low or not_detected field OR selector) has been
 * reviewed/edited by the merchant. NOTHING auto-approves; a scan never self-confirms.
 *
 * This is the one source of truth both sides read:
 *  - the A4 UI disables "Confirm product" and shows scan.blocked.reason while the
 *    gate is closed, listing exactly which rows still block it;
 *  - the server-side confirm action calls the SAME gate before transitioning, so a
 *    crafted request can never bypass the UI and confirm an unreviewed scan.
 *
 * "Reviewed" is supplied by the caller: the read model's default view treats every
 * blocking row as still-unreviewed (so the form opens with the work to do listed);
 * the confirm action passes the set of row keys the merchant touched/acknowledged.
 */
final readonly class ConfirmGate
{
    /**
     * @param  array<int,string>  $blockingKeys  keys of rows that block confirm and are NOT yet reviewed
     * @param  array<int,array{kind: string, key: string, level: string}>  $blockingRows  detail for the UI
     */
    public function __construct(
        public bool $canConfirm,
        public array $blockingKeys,
        public array $blockingRows,
    ) {}

    /**
     * Evaluate the gate over a set of rows, given which row keys the merchant has
     * reviewed. With no reviewed keys (the default review view) every blocking row
     * is listed; the gate opens only once all of them are reviewed.
     *
     * @param  array<int,ScanReviewRow>  $rows
     * @param  array<int,string>  $reviewedKeys  "{kind}:{key}" identifiers the merchant touched
     */
    public static function evaluate(array $rows, array $reviewedKeys = []): self
    {
        $reviewed = array_fill_keys($reviewedKeys, true);

        $blockingKeys = [];
        $blockingRows = [];

        foreach ($rows as $row) {
            if (! $row->blocksConfirm()) {
                continue;
            }

            $identifier = self::identifier($row);

            if (isset($reviewed[$identifier])) {
                continue; // the merchant reviewed/edited this blocking row
            }

            $blockingKeys[] = $identifier;
            $blockingRows[] = [
                'kind' => $row->kind,
                'key' => $row->key,
                'level' => $row->level->level,
            ];
        }

        return new self(
            canConfirm: $blockingKeys === [],
            blockingKeys: $blockingKeys,
            blockingRows: $blockingRows,
        );
    }

    /** The stable per-row identifier the reviewed-set keys off ("field:price"). */
    public static function identifier(ScanReviewRow $row): string
    {
        return $row->kind.':'.$row->key;
    }

    /** The i18n key the UI shows when confirm is blocked (design-tokens §5 / i18n catalog). */
    public function blockedReasonKey(): ?string
    {
        return $this->canConfirm ? null : 'scan.blocked.reason';
    }

    public function toArray(): array
    {
        return [
            'can_confirm' => $this->canConfirm,
            'blocking_keys' => $this->blockingKeys,
            'blocking_rows' => $this->blockingRows,
            'blocked_reason_key' => $this->blockedReasonKey(),
        ];
    }
}
