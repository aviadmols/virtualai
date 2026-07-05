<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Review\ConfirmScanAction;
use App\Domain\Scan\Review\ConfirmScanInput;
use App\Domain\Scan\Review\DimensionPicker;
use App\Domain\Scan\ScanConstants;
use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WS4 — visual product-data picking, dimension roles (size / weight).
 *
 * Proves the SEAM decision: size/weight are a SEPARATE DIMENSION_ROLES set (never
 * in SELECTOR_ROLES / detected_selectors), and a visually-picked dimension source
 * round-trips through the confirm path into Product.physical_dimensions with BOTH
 * the picked selector and the value read from that element at confirm time.
 */
class DimensionPickTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT_HTML = <<<'HTML'
    <html><body>
      <h1 class="product__title">Merino Sweater</h1>
      <span class="product__size">Size: Large</span>
      <span class="product__weight">Weight: 420g</span>
      <span class="dup">a</span><span class="dup">b</span>
    </body></html>
    HTML;

    // === SEAM: dimension roles are separate from the runtime selector roles ===

    public function test_dimension_roles_are_size_and_weight_and_are_not_runtime_selector_roles(): void
    {
        $this->assertSame([ScanConstants::ROLE_SIZE, ScanConstants::ROLE_WEIGHT], ScanConstants::DIMENSION_ROLES);

        // The widget-runtime selector contract must stay exactly the six roles — a
        // size/weight pick must never pollute detected_selectors.
        foreach (ScanConstants::DIMENSION_ROLES as $role) {
            $this->assertNotContains($role, ScanConstants::SELECTOR_ROLES, "dimension role {$role} leaked into SELECTOR_ROLES");
        }

        $this->assertCount(6, ScanConstants::SELECTOR_ROLES);
    }

    // === DimensionPicker: verify + read the value ===

    public function test_dimension_picker_reads_value_when_selector_resolves_to_one(): void
    {
        $dom = ScanDom::fromHtml(self::SNAPSHOT_HTML);
        $picker = new DimensionPicker;

        $size = $picker->pick($dom, ScanConstants::ROLE_SIZE, '.product__size');

        $this->assertTrue($size->resolvesToOne());
        $this->assertSame(1, $size->matchedCount);
        $this->assertSame('Size: Large', $size->value);
    }

    public function test_dimension_picker_returns_no_value_on_zero_or_multiple_matches(): void
    {
        $dom = ScanDom::fromHtml(self::SNAPSHOT_HTML);
        $picker = new DimensionPicker;

        $absent = $picker->pick($dom, ScanConstants::ROLE_WEIGHT, '#nope');
        $this->assertSame(0, $absent->matchedCount);
        $this->assertNull($absent->value);

        $many = $picker->pick($dom, ScanConstants::ROLE_WEIGHT, '.dup');
        $this->assertSame(2, $many->matchedCount);
        $this->assertNull($many->value);
        $this->assertFalse($many->resolvesToOne());
    }

    public function test_dimension_picker_rejects_an_unknown_role(): void
    {
        $dom = ScanDom::fromHtml(self::SNAPSHOT_HTML);

        $result = (new DimensionPicker)->pick($dom, 'colour', '.product__size');

        $this->assertSame(0, $result->matchedCount);
        $this->assertNull($result->value);
    }

    // === ConfirmScanInput: only known dimension roles, blank selectors dropped ===

    public function test_confirm_input_keeps_only_known_dimension_roles(): void
    {
        $input = ConfirmScanInput::fromArray([
            'dimension_picks' => [
                ScanConstants::ROLE_SIZE => ['selector' => '.product__size', 'value' => 'Size: Large'],
                ScanConstants::ROLE_WEIGHT => ['selector' => '  ', 'value' => 'x'], // blank selector -> dropped
                'colour' => ['selector' => '.c', 'value' => 'Red'],                  // unknown role -> dropped
            ],
        ]);

        $this->assertArrayHasKey(ScanConstants::ROLE_SIZE, $input->dimensionPicks);
        $this->assertArrayNotHasKey(ScanConstants::ROLE_WEIGHT, $input->dimensionPicks);
        $this->assertArrayNotHasKey('colour', $input->dimensionPicks);
        $this->assertSame('.product__size', $input->dimensionPicks[ScanConstants::ROLE_SIZE]['selector']);
        $this->assertSame('Size: Large', $input->dimensionPicks[ScanConstants::ROLE_SIZE]['value']);
    }

    // === ROUND-TRIP: pick -> confirm -> physical_dimensions ===

    public function test_dimension_pick_round_trips_through_confirm_into_physical_dimensions(): void
    {
        [$account, $product] = $this->allHighProduct();

        // The merchant marks size + weight in the preview; the server reads their values.
        $dom = ScanDom::fromHtml(self::SNAPSHOT_HTML);
        $picker = new DimensionPicker;
        $size = $picker->pick($dom, ScanConstants::ROLE_SIZE, '.product__size');
        $weight = $picker->pick($dom, ScanConstants::ROLE_WEIGHT, '.product__weight');

        $input = ConfirmScanInput::fromArray([
            'reviewed_keys' => [],
            'dimension_picks' => [
                ScanConstants::ROLE_SIZE => ['selector' => $size->selector, 'value' => $size->value],
                ScanConstants::ROLE_WEIGHT => ['selector' => $weight->selector, 'value' => $weight->value],
            ],
        ]);

        $confirmed = (new ConfirmScanAction)->confirm($product, $input);

        // The picks land under physical_dimensions.picks — NOT in detected_selectors.
        $picks = $confirmed->physical_dimensions[ScanConstants::DIMENSION_PICKS_KEY];
        $this->assertSame('.product__size', $picks[ScanConstants::ROLE_SIZE][ScanConstants::DIMENSION_PICK_SELECTOR]);
        $this->assertSame('Size: Large', $picks[ScanConstants::ROLE_SIZE][ScanConstants::DIMENSION_PICK_VALUE]);
        $this->assertSame('Weight: 420g', $picks[ScanConstants::ROLE_WEIGHT][ScanConstants::DIMENSION_PICK_VALUE]);

        // The AI-extracted dimensions at the top level are untouched by the pick merge.
        $this->assertSame(['M' => ['chest' => 100]], $confirmed->physical_dimensions['size_map']);

        // The six runtime selector roles are unchanged — no size/weight leaked in.
        foreach (ScanConstants::DIMENSION_ROLES as $role) {
            $this->assertArrayNotHasKey($role, $confirmed->detected_selectors);
        }
    }

    public function test_a_dimension_pick_does_not_require_review_and_never_blocks_confirm(): void
    {
        // A product with NO dimension picks still confirms — size/weight are optional.
        [, $product] = $this->allHighProduct();

        $confirmed = (new ConfirmScanAction)->confirm($product, ConfirmScanInput::fromArray(['reviewed_keys' => []]));

        $this->assertTrue($confirmed->isConfirmed());
    }

    // === HELPERS (mirror ScanReviewContractTest) ===

    /** @return array{0: Account, 1: Product} */
    private function allHighProduct(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $fields = [];
        foreach (['name', 'price', 'description', 'product_type', 'main_image_url'] as $f) {
            $fields[$f] = ['value' => 'v', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD];
        }

        $selectors = [];
        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $selectors[$role] = ['primary' => '#'.$role, 'fallback_chain' => [], 'confidence' => 0.95, 'matched_count' => 1, 'needs_review' => false];
        }

        $product = Tenant::run($account, fn () => Product::factory()->forSite($site)->create([
            'field_confidence' => $fields,
            'physical_dimensions' => ['size_map' => ['M' => ['chest' => 100]]],
            'detected_selectors' => $selectors,
        ]));

        return [$account, $product];
    }
}
