<?php

namespace Tests\Feature\Sites;

use App\Domain\Sites\ButtonVisibility;
use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ButtonVisibility — the merchant's "where does the Try-it-on button appear" rule.
 *
 * Proves FAIL-OPEN defaults (no rule / MODE_ALL / empty values all show everywhere), the
 * tag / product_type / collection matching (case-insensitive, handle OR title for a
 * collection), and that a non-matching product is excluded.
 */
class ButtonVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attrs = [], array $shopify = []): Product
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        return Product::factory()->forSite($site)->create($attrs + [
            'scan_raw' => ['shopify' => $shopify],
        ]);
    }

    public function test_no_rule_and_mode_all_show_on_every_product(): void
    {
        $product = $this->product(['product_type' => 'shirt']);

        $this->assertTrue(ButtonVisibility::resolve(null)->matches($product));
        $this->assertTrue(ButtonVisibility::resolve([])->matches($product));
        $this->assertTrue(ButtonVisibility::resolve(['mode' => 'all'])->matches($product));
    }

    public function test_a_mode_with_no_values_still_shows_everywhere(): void
    {
        $product = $this->product(['product_type' => 'shirt']);

        // A half-filled rule (a mode but no values) must never hide the whole store.
        $this->assertTrue(ButtonVisibility::resolve(['mode' => 'tag', 'values' => []])->matches($product));
    }

    public function test_product_type_mode_matches_case_insensitively(): void
    {
        $shirt = $this->product(['product_type' => 'Shirt']);
        $shoe = $this->product(['product_type' => 'Shoe']);

        $rule = ButtonVisibility::resolve(['mode' => 'product_type', 'values' => ['shirt', 'dress']]);

        $this->assertTrue($rule->matches($shirt));   // 'Shirt' == 'shirt'
        $this->assertFalse($rule->matches($shoe));
    }

    public function test_tag_mode_matches_a_shopify_tag(): void
    {
        $tagged = $this->product([], ['tags' => ['Summer', 'tryon']]);
        $other = $this->product([], ['tags' => ['winter']]);

        $rule = ButtonVisibility::resolve(['mode' => 'tag', 'values' => ['tryon']]);

        $this->assertTrue($rule->matches($tagged));
        $this->assertFalse($rule->matches($other));
    }

    public function test_collection_mode_matches_by_handle_or_title(): void
    {
        $inCollection = $this->product([], ['collections' => [
            ['handle' => 'summer-sale', 'title' => 'Summer Sale'],
        ]]);
        $other = $this->product([], ['collections' => [
            ['handle' => 'clearance', 'title' => 'Clearance'],
        ]]);

        // Merchant typed the TITLE.
        $byTitle = ButtonVisibility::resolve(['mode' => 'collection', 'values' => ['Summer Sale']]);
        $this->assertTrue($byTitle->matches($inCollection));
        $this->assertFalse($byTitle->matches($other));

        // Merchant typed the HANDLE.
        $byHandle = ButtonVisibility::resolve(['mode' => 'collection', 'values' => ['summer-sale']]);
        $this->assertTrue($byHandle->matches($inCollection));
    }

    public function test_a_product_missing_the_metadata_does_not_match_a_specific_rule(): void
    {
        // A product with no tags at all can't match a tag rule (but the rule is specific, so
        // this correctly hides the button there — the store-wide fail-open is only for empty rules).
        $noTags = $this->product([], ['tags' => []]);

        $rule = ButtonVisibility::resolve(['mode' => 'tag', 'values' => ['tryon']]);
        $this->assertFalse($rule->matches($noTags));
    }

    public function test_sanitize_normalizes_mode_and_dedupes_values(): void
    {
        $clean = ButtonVisibility::sanitize([
            'mode' => 'nonsense',
            'values' => ['a', 'A', ' a ', 'b', ''],
        ]);

        $this->assertSame('all', $clean['mode']); // unknown mode -> all
        $this->assertSame(['a', 'b'], $clean['values']); // trimmed, case-deduped, empties dropped
    }
}
