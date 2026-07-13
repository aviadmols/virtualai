<?php

namespace App\Domain\Products;

use App\Models\Product;

/**
 * PersistResult — what one PersistProduct call did, so the caller (a sync run, a scan
 * job, a webhook) can count without re-reading the DB.
 */
final readonly class PersistResult
{
    public function __construct(
        public Product $product,
        public bool $created,
        public int $variantsUpserted,
        public int $variantsArchived,
        public bool $statusPreserved,
    ) {}

    public function wasUpdated(): bool
    {
        return ! $this->created;
    }
}
