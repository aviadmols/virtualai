<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Contract\SelectorReverifier;
use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Review\ConfidenceLevel;
use App\Domain\Scan\Review\ConfirmGate;
use App\Domain\Scan\Review\ConfirmScanAction;
use App\Domain\Scan\Review\ConfirmScanInput;
use App\Domain\Scan\Review\ScanConfirmBlockedException;
use App\Domain\Scan\Review\ScanReview;
use App\Domain\Scan\Review\ScanReviewRow;
use App\Domain\Scan\Review\SelectorTester;
use App\Domain\Scan\Review\SelectorTestResult;
use App\Domain\Scan\ScanConstants;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Phase-8e SCAN-REVIEW read/write contract: the per-field/-selector read model
 * with bucketed confidence, the no-auto-approve confirm gate (enforced server-side),
 * the typed selector-test result, and the confirm/correct write path.
 */
class ScanReviewContractTest extends TestCase
{
    use RefreshDatabase;

    // === CONFIDENCE BUCKETING (the single source) ===

    public function test_confidence_level_buckets_score_into_the_four_contract_levels(): void
    {
        $this->assertSame(ScanConstants::LEVEL_HIGH, ConfidenceLevel::fromScore(0.95)->level);
        $this->assertSame(ScanConstants::LEVEL_HIGH, ConfidenceLevel::fromScore(ScanConstants::REVIEW_FLOOR)->level);
        $this->assertSame(ScanConstants::LEVEL_MEDIUM, ConfidenceLevel::fromScore(0.5)->level);
        $this->assertSame(ScanConstants::LEVEL_LOW, ConfidenceLevel::fromScore(0.2)->level);
        $this->assertSame(ScanConstants::LEVEL_NOT_DETECTED, ConfidenceLevel::fromScore(0.0)->level);
        $this->assertSame(ScanConstants::LEVEL_NOT_DETECTED, ConfidenceLevel::fromScore(null)->level);
        // A score with detected=false is not_detected even if numeric.
        $this->assertSame(ScanConstants::LEVEL_NOT_DETECTED, ConfidenceLevel::fromScore(0.9, detected: false)->level);
    }

    public function test_only_low_and_not_detected_levels_block_confirm(): void
    {
        $this->assertFalse(ConfidenceLevel::fromScore(0.95)->blocksConfirm());
        $this->assertFalse(ConfidenceLevel::fromScore(0.5)->blocksConfirm());
        $this->assertTrue(ConfidenceLevel::fromScore(0.2)->blocksConfirm());
        $this->assertTrue(ConfidenceLevel::notDetected()->blocksConfirm());
    }

    public function test_confidence_level_maps_to_the_design_token_i18n_keys(): void
    {
        $this->assertSame('scan.confidence.high', ConfidenceLevel::fromScore(0.95)->i18nKey());
        $this->assertSame('scan.confidence.medium', ConfidenceLevel::fromScore(0.5)->i18nKey());
        $this->assertSame('scan.confidence.low', ConfidenceLevel::fromScore(0.2)->i18nKey());
        $this->assertSame('scan.confidence.none', ConfidenceLevel::notDetected()->i18nKey());
    }

    // === READ MODEL ===

    public function test_read_model_exposes_every_field_and_every_selector_with_levels(): void
    {
        [$account, $product] = $this->draftProduct([
            'field_confidence' => [
                'name' => ['value' => 'Merino Sweater', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD],
                'price' => ['value' => 4995, 'currency' => 'EUR', 'confidence' => 0.4, 'source' => ScanConstants::SOURCE_MODEL_INFERRED],
                'description' => ['value' => 'Soft.', 'confidence' => 0.55, 'source' => ScanConstants::SOURCE_OG],
                // product_type + main_image_url intentionally absent -> not_detected
            ],
            'detected_selectors' => [
                ScanConstants::ROLE_ADD_TO_CART => ['primary' => '#add', 'fallback_chain' => ['.add'], 'confidence' => 0.95, 'matched_count' => 1, 'needs_review' => false],
                ScanConstants::ROLE_PRICE => ['primary' => '.price', 'fallback_chain' => [], 'confidence' => 0.9, 'matched_count' => 3, 'needs_review' => true],
                // remaining roles absent -> not_detected
            ],
        ]);

        $review = Tenant::run($account, fn () => ScanReview::fromProduct($product));

        // Every reviewable product field is a row (5 scalars + variants + dimensions).
        $fieldKeys = array_map(fn (ScanReviewRow $r) => $r->key, $review->fieldRows);
        foreach (['name', 'price', 'description', 'product_type', 'main_image_url', 'variants', 'physical_dimensions'] as $key) {
            $this->assertContains($key, $fieldKeys, "missing field row: {$key}");
        }

        // All six selector roles are rows.
        $selectorKeys = array_map(fn (ScanReviewRow $r) => $r->key, $review->selectorRows);
        $this->assertSame(ScanConstants::SELECTOR_ROLES, $selectorKeys);

        $rowsByKey = collect($review->rows())->keyBy(fn (ScanReviewRow $r) => $r->kind.':'.$r->key);

        // High jsonld name -> high; low model_inferred price -> low; absent type -> not_detected.
        $this->assertSame(ScanConstants::LEVEL_HIGH, $rowsByKey['field:name']->level->level);
        $this->assertSame(ScanConstants::LEVEL_LOW, $rowsByKey['field:price']->level->level);
        $this->assertSame(ScanConstants::LEVEL_NOT_DETECTED, $rowsByKey['field:product_type']->level->level);
        $this->assertSame(ScanConstants::LEVEL_NOT_DETECTED, $rowsByKey['field:main_image_url']->level->level);

        // A selector matching 3 elements is clamped below high (needs review).
        $priceSelector = $rowsByKey['selector:'.ScanConstants::ROLE_PRICE];
        $this->assertSame(3, $priceSelector->matchedElementCount);
        $this->assertNotSame(ScanConstants::LEVEL_HIGH, $priceSelector->level->level);
        $this->assertTrue($priceSelector->blocksConfirm());

        // An absent selector is not_detected (manual entry required).
        $this->assertSame(ScanConstants::LEVEL_NOT_DETECTED, $rowsByKey['selector:'.ScanConstants::ROLE_TITLE]->level->level);

        // Variants + dimensions are OPTIONAL: shown as not_detected but never blocking.
        $this->assertTrue($rowsByKey['field:variants']->optional);
        $this->assertFalse($rowsByKey['field:variants']->blocksConfirm());
        $this->assertTrue($rowsByKey['field:physical_dimensions']->optional);
        $this->assertFalse($rowsByKey['field:physical_dimensions']->blocksConfirm());
    }

    public function test_read_model_array_is_ui_ready_with_confidence_and_label_keys(): void
    {
        [$account, $product] = $this->draftProduct([
            'field_confidence' => ['name' => ['value' => 'X', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD]],
        ]);

        $array = Tenant::run($account, fn () => ScanReview::fromProduct($product)->toArray());

        $name = collect($array['fields'])->firstWhere('key', 'name');
        $this->assertSame('scan.field.title', $name['label_key']);
        $this->assertSame(ScanConstants::LEVEL_HIGH, $name['confidence_level']);
        $this->assertSame('scan.confidence.high', $name['confidence_i18n_key']);
        $this->assertTrue($name['editable']);

        $cart = collect($array['selectors'])->firstWhere('key', ScanConstants::ROLE_ADD_TO_CART);
        $this->assertSame('scan.selector.add_to_cart', $cart['label_key']);
    }

    // === CONFIRM GATE ===

    public function test_gate_is_closed_while_a_low_or_not_detected_row_is_unreviewed(): void
    {
        [$account, $product] = $this->lowConfidenceProduct();

        $review = Tenant::run($account, fn () => ScanReview::fromProduct($product));

        // No reviewed keys -> the blocking rows keep the gate closed.
        $this->assertFalse($review->gate->canConfirm);
        $this->assertNotEmpty($review->gate->blockingKeys);
        $this->assertSame('scan.blocked.reason', $review->gate->blockedReasonKey());
    }

    public function test_gate_opens_once_every_blocking_row_is_reviewed(): void
    {
        [$account, $product] = $this->lowConfidenceProduct();

        Tenant::run($account, function () use ($product) {
            $rows = ScanReview::fromProduct($product)->rows();
            $blocking = ConfirmGate::evaluate($rows)->blockingKeys;

            // Review every blocking row -> the gate opens.
            $open = ConfirmGate::evaluate($rows, $blocking);
            $this->assertTrue($open->canConfirm);
            $this->assertNull($open->blockedReasonKey());

            // Reviewing only SOME blocking rows keeps it closed.
            $partial = ConfirmGate::evaluate($rows, [array_shift($blocking)]);
            $this->assertFalse($partial->canConfirm);
        });
    }

    public function test_an_all_high_scan_can_confirm_with_no_review(): void
    {
        [$account, $product] = $this->allHighProduct();

        $review = Tenant::run($account, fn () => ScanReview::fromProduct($product));

        $this->assertTrue($review->gate->canConfirm);
    }

    // === SELECTOR-TEST CONTRACT ===

    public function test_selector_test_result_maps_count_to_typed_outcome(): void
    {
        $this->assertSame(SelectorTestResult::OUTCOME_MATCHED, SelectorTestResult::fromCount('#a', 1, ScanConstants::STRATEGY_ID)->outcome);
        $this->assertSame(SelectorTestResult::OUTCOME_MULTIPLE, SelectorTestResult::fromCount('.b', 3, ScanConstants::STRATEGY_CLASS)->outcome);
        $this->assertSame(SelectorTestResult::OUTCOME_NOT_FOUND, SelectorTestResult::fromCount('#c', 0, ScanConstants::STRATEGY_ID)->outcome);
        $this->assertTrue(SelectorTestResult::fromCount('#a', 1, ScanConstants::STRATEGY_ID)->resolvesToOne());
        $this->assertSame('scan.selector.test_ok', SelectorTestResult::fromCount('#a', 1, ScanConstants::STRATEGY_ID)->i18nKey());
    }

    public function test_selector_tester_against_dom_returns_typed_results(): void
    {
        $html = '<html><body><button id="add-to-cart">Add</button><span class="dup">a</span><span class="dup">b</span></body></html>';
        $tester = new SelectorTester(new SelectorReverifier($this->nullFetcher()));

        $results = $tester->testAgainstDom(ScanDom::fromHtml($html), ['#add-to-cart', '.dup', '#absent']);

        $this->assertSame(SelectorTestResult::OUTCOME_MATCHED, $results[0]->outcome);
        $this->assertSame(SelectorTestResult::OUTCOME_MULTIPLE, $results[1]->outcome);
        $this->assertSame(SelectorTestResult::OUTCOME_NOT_FOUND, $results[2]->outcome);
    }

    public function test_selector_tester_turns_a_fetch_failure_into_an_error_outcome(): void
    {
        $tester = new SelectorTester(new SelectorReverifier($this->failingFetcher()));

        $results = $tester->testAgainstLivePage('https://blocked.example.com/p', ['#add']);

        $this->assertSame(SelectorTestResult::OUTCOME_ERROR, $results[0]->outcome);
        $this->assertSame(ScanConstants::FAIL_BOT_BLOCKED, $results[0]->errorReason);
    }

    // === WRITE CONTRACT ===

    public function test_confirm_action_persists_corrections_and_confirms(): void
    {
        [$account, $product] = $this->allHighProduct();
        $variant = Tenant::run($account, fn () => ProductVariant::factory()->forProduct($product)->create(['sku' => 'OLD']));

        $input = ConfirmScanInput::fromArray([
            'fields' => ['name' => 'Corrected Name', 'price_minor' => 7999, 'status' => 'confirmed'],
            'selectors' => [ScanConstants::ROLE_ADD_TO_CART => '#new-add', 'evil' => '#x'],
            'variants' => [['id' => $variant->id, 'sku' => 'NEW-SKU']],
            'reviewed_keys' => [],
        ]);

        $confirmed = (new ConfirmScanAction)->confirm($product, $input);

        $this->assertTrue($confirmed->isConfirmed());
        $this->assertSame('Corrected Name', $confirmed->name);
        $this->assertSame(7999, $confirmed->price_minor);
        // status is NOT mass-assignable from the payload — the action set it.
        $this->assertSame(Product::STATUS_CONFIRMED, $confirmed->status);

        // The chosen selector is merged + marked confirmed; the unknown role dropped.
        $selectors = $confirmed->detected_selectors;
        $this->assertSame('#new-add', $selectors[ScanConstants::ROLE_ADD_TO_CART]['primary']);
        $this->assertTrue($selectors[ScanConstants::ROLE_ADD_TO_CART]['confirmed']);
        $this->assertArrayNotHasKey('evil', $selectors);

        $this->assertSame('NEW-SKU', $confirmed->variants->firstWhere('id', $variant->id)->sku);
    }

    public function test_confirm_action_enforces_the_gate_server_side(): void
    {
        [$account, $product] = $this->lowConfidenceProduct();

        // A crafted request with NO reviewed keys must be refused server-side.
        $input = ConfirmScanInput::fromArray(['reviewed_keys' => []]);

        $this->expectException(ScanConfirmBlockedException::class);

        Tenant::run($account, fn () => (new ConfirmScanAction)->confirm($product, $input));
    }

    public function test_confirm_action_succeeds_once_blocking_rows_are_reviewed(): void
    {
        [$account, $product] = $this->lowConfidenceProduct();

        $blocking = Tenant::run($account, fn () => ConfirmGate::evaluate(ScanReview::fromProduct($product)->rows())->blockingKeys);

        $input = ConfirmScanInput::fromArray(['reviewed_keys' => $blocking]);

        $confirmed = (new ConfirmScanAction)->confirm($product, $input);

        $this->assertTrue($confirmed->isConfirmed());
    }

    // === HELPERS ===

    /** @return array{0: Account, 1: Product} */
    private function draftProduct(array $attributes): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $product = Tenant::run($account, fn () => Product::factory()->forSite($site)->create($attributes));

        return [$account, $product];
    }

    /** A scan with a low + a not_detected row (the gate must block it). */
    private function lowConfidenceProduct(): array
    {
        return $this->draftProduct([
            'field_confidence' => [
                'name' => ['value' => 'X', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD],
                'price' => ['value' => 100, 'currency' => 'USD', 'confidence' => 0.2, 'source' => ScanConstants::SOURCE_MODEL_INFERRED],
            ],
            'physical_dimensions' => [],
            'detected_selectors' => [],
        ]);
    }

    /** A scan where every field + selector is high (gate open, no review needed). */
    private function allHighProduct(): array
    {
        $fields = [];
        foreach (['name', 'price', 'description', 'product_type', 'main_image_url'] as $f) {
            $fields[$f] = ['value' => 'v', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD];
        }

        $selectors = [];
        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $selectors[$role] = ['primary' => '#'.$role, 'fallback_chain' => [], 'confidence' => 0.95, 'matched_count' => 1, 'needs_review' => false];
        }

        return $this->draftProduct([
            'field_confidence' => $fields,
            'physical_dimensions' => ['size_map' => ['M' => ['chest' => 100]]],
            'detected_selectors' => $selectors,
        ]);
    }

    private function nullFetcher(): PageSource
    {
        return new class implements PageSource
        {
            public function fetch(string $url): FetchResult
            {
                return new FetchResult('<html></html>', $url, ScanConstants::FETCH_VIA_HTTP);
            }
        };
    }

    private function failingFetcher(): PageSource
    {
        return new class implements PageSource
        {
            public function fetch(string $url): FetchResult
            {
                throw FetchException::failed(ScanConstants::FAIL_BOT_BLOCKED);
            }
        };
    }
}
