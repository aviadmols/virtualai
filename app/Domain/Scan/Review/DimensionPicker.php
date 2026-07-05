<?php

namespace App\Domain\Scan\Review;

use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\ScanConstants;

/**
 * DimensionPicker — verify a merchant-picked size/weight source selector against a
 * page DOM and read the value it points at.
 *
 * A dimension pick differs from a runtime selector pick: the merchant marks WHERE
 * on the page the size/weight is shown, and we capture BOTH the selector (for
 * re-verification / audit) AND the current text value (the fit hint the try-on
 * prompt consumes). The value is read once, here, from the DOM the picker rendered
 * — never trusted from the untrusted client. Purely reads a ScanDom (no network,
 * no persistence) so it stays unit-testable; the DOM is supplied by the caller
 * from the stored snapshot (PreviewSnapshotStore) or a live re-fetch.
 */
final readonly class DimensionPicker
{
    /**
     * Verify + read a single picked dimension selector against the given DOM.
     * A 0/>1 match yields a null value (nothing trustworthy to read) and the raw
     * count so the review row can flag it, exactly like a selector test.
     */
    public function pick(ScanDom $dom, string $role, string $selector): DimensionPickResult
    {
        $selector = trim($selector);

        if ($selector === '' || ! in_array($role, ScanConstants::DIMENSION_ROLES, true)) {
            return new DimensionPickResult($role, $selector, 0, null);
        }

        $count = $dom->count($selector);
        $value = $count === 1 ? $dom->text($selector) : null;

        return new DimensionPickResult($role, $selector, $count, $value);
    }
}
