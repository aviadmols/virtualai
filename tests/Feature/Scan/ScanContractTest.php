<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Contract\ScanContract;
use App\Domain\Scan\ScanConstants;
use App\Models\Account;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The confirm/correct contract shape + the guarded status machine. Proves the UI
 * contract exposes every editable field + the six selector slots + the element-pick
 * shape, and that only the canonical draft->confirmed transition is legal.
 */
class ScanContractTest extends TestCase
{
    use RefreshDatabase;

    private function draftProduct(Account $account, Site $site): Product
    {
        return Tenant::run($account, fn () => Product::factory()->forSite($site)->create([
            'field_confidence' => [
                'name' => ['value' => 'Sweater', 'confidence' => 0.95, 'source' => ScanConstants::SOURCE_JSONLD],
                'price' => ['value' => 4995, 'currency' => 'EUR', 'confidence' => 0.4, 'source' => ScanConstants::SOURCE_MODEL_INFERRED],
            ],
            'detected_selectors' => [
                ScanConstants::ROLE_ADD_TO_CART => ['primary' => '#add', 'fallback_chain' => ['.add'], 'confidence' => 0.95, 'matched_count' => 1, 'needs_review' => false],
            ],
        ]));
    }

    public function test_contract_exposes_editable_fields_with_confidence_and_review_flag(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $product = $this->draftProduct($account, $site);

        $contract = Tenant::run($account, fn () => ScanContract::forProduct($product->fresh('variants')));

        $this->assertSame(Product::STATUS_DRAFT, $contract['status']);

        // Every field editable; a high-confidence jsonld name is not flagged.
        $this->assertTrue($contract['fields']['name']['editable']);
        $this->assertFalse($contract['fields']['name']['needs_review']);

        // A low-confidence model_inferred price IS flagged for review.
        $this->assertTrue($contract['fields']['price']['needs_review']);

        // All six selector slots present, each manually overridable.
        foreach (ScanConstants::SELECTOR_ROLES as $role) {
            $this->assertArrayHasKey($role, $contract['selectors']);
            $this->assertTrue($contract['selectors'][$role]['manual_override']);
        }

        // The element-pick payload shape is defined for admin-design-system.
        $this->assertArrayHasKey('css_path', $contract['element_pick_shape']);
        $this->assertArrayHasKey('suggested_selectors', $contract['element_pick_shape']);
    }

    public function test_confirm_transitions_draft_to_confirmed_only(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $product = $this->draftProduct($account, $site);

        Tenant::run($account, function () use ($product) {
            $product->confirm();
            $this->assertTrue($product->isConfirmed());
            $this->assertNotNull($product->confirmed_at);
        });
    }

    public function test_confirming_a_confirmed_product_is_an_illegal_transition(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $product = Tenant::run($account, fn () => Product::factory()->forSite($site)->confirmed()->create());

        $this->expectException(RuntimeException::class);

        Tenant::run($account, fn () => $product->confirm());
    }

    public function test_failed_product_can_be_rescanned_back_to_draft(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($site) {
            $product = Product::factory()->forSite($site)->create(['status' => Product::STATUS_FAILED]);

            // failed -> draft is the legal re-scan recovery.
            $product->transitionTo(Product::STATUS_DRAFT);
            $this->assertSame(Product::STATUS_DRAFT, $product->status);
        });
    }
}
