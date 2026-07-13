<?php

namespace App\Domain\Shopify\Products;

use RuntimeException;

/**
 * ShopifyProductNotFoundException — the Admin API answered, and the product is gone
 * (deleted, or never visible to this app). Distinct from a transport/throttle failure:
 * the caller ARCHIVES the local product rather than retrying, because retrying a
 * deleted product forever is how a sync run dies.
 */
final class ShopifyProductNotFoundException extends RuntimeException {}
