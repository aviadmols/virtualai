<?php

namespace Tests\Feature\ProductImages;

use App\Domain\ProductImages\BatchResult;
use App\Domain\ProductImages\PollProductImageJob;
use App\Domain\ProductImages\RegenerateProductImage;
use App\Domain\ProductImages\StartProductImageBatch;
use App\Domain\ProductImages\SubmitProductImageJob;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\CreditLedger;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * "Update prompt" — a guided regenerate: the merchant edits the art-direction note and the image
 * is regenerated from the ORIGINAL product photo with the new note. Proves a changed note is a
 * genuinely new (separately-charged) image that regenerates from the PHOTO (not the result — the
 * line that separates it from "Fix image"), while an unchanged note collapses on the same intent.
 */
class ProductImageUpdatePromptTest extends TestCase
{
    use ProductImageTestSupport, RefreshDatabase;

    private const MAIN_IMAGE = 'https://cdn.example.com/product-main.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductImageEnv();
        $this->fakeFal();
        Bus::fake([SubmitProductImageJob::class, PollProductImageJob::class]);
    }

    private function startBatch(array $shop): array
    {
        $result = Tenant::run($shop['account'], fn () => app(StartProductImageBatch::class)->handle(
            site: $shop['site'],
            productIds: [(int) $shop['product']->getKey()],
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION,
            sourcePick: ProductImageBatch::SOURCE_MAIN,
        ));

        $this->assertSame(1, $result->queued);

        return [$shop['account'], $shop['site'], Tenant::run($shop['account'], fn () => ProductAsset::query()->latest('id')->firstOrFail())];
    }

    private function runSubmit(Account $account, Site $site, ProductAsset $asset): void
    {
        (new SubmitProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    private function runPoll(Account $account, Site $site, ProductAsset $asset): void
    {
        (new PollProductImageJob((int) $account->getKey(), (int) $site->getKey(), (int) $asset->getKey()))->handle();
    }

    private function succeedBase(): array
    {
        [$account, $site, $asset] = $this->startBatch($this->makeShop());
        $this->runSubmit($account, $site, $asset);
        $this->runPoll($account, $site, $asset);
        $asset->refresh();
        $this->assertTrue($asset->isSucceeded());

        return [$account, $site, $asset];
    }

    private function updatePrompt(Account $account, Site $site, int $assetId, ?string $note): BatchResult
    {
        return Tenant::run($account, fn () => app(RegenerateProductImage::class)->handle($site, $assetId, $note));
    }

    /** @return Collection<int,CreditLedger> */
    private function charges(Account $account)
    {
        return Tenant::run($account, fn () => CreditLedger::query()->where('type', CreditLedger::TYPE_CHARGE)->get());
    }

    public function test_update_prompt_regenerates_from_the_photo_with_the_edited_note_and_charges_once(): void
    {
        [$account, $site, $base] = $this->succeedBase();

        $result = $this->updatePrompt($account, $site, (int) $base->id, 'warm beige studio backdrop');
        $this->assertSame(1, $result->queued);

        $child = Tenant::run($account, fn () => ProductAsset::query()->latest('id')->firstOrFail());
        $this->assertSame((int) $base->id, (int) $child->source_asset_id);
        $this->assertSame('regen-'.$base->id.'-0', $child->client_request_id);
        $this->assertNotSame($base->idempotency_key, $child->idempotency_key);

        // Regenerated from the ORIGINAL product photo (NOT the result — the fix/update-prompt split).
        $childBatch = Tenant::run($account, fn () => ProductImageBatch::query()->findOrFail($child->batch_id));
        $this->assertSame(ProductImageBatch::SOURCE_MAIN, $childBatch->source_pick);
        $this->assertSame(sha1(self::MAIN_IMAGE), $child->source_image_hash);
        // The edited note is carried on the child batch.
        $this->assertSame('warm beige studio backdrop', $childBatch->notes);

        // Drive → exactly one NEW charge (base + this regenerate).
        $this->runSubmit($account, $site, $child);
        $this->runPoll($account, $site, $child);
        $this->assertCount(2, $this->charges($account));
    }

    public function test_a_double_clicked_update_prompt_with_the_same_note_charges_once(): void
    {
        [$account, $site, $base] = $this->succeedBase();
        $submitsBefore = $this->falSubmitCount();

        $first = $this->updatePrompt($account, $site, (int) $base->id, 'same note');
        $second = $this->updatePrompt($account, $site, (int) $base->id, 'same note');

        $this->assertSame(1, $first->queued);
        $this->assertSame(0, $second->queued);
        $this->assertSame(1, $second->skippedExisting);

        $children = Tenant::run($account, fn () => ProductAsset::query()->where('source_asset_id', $base->id)->get());
        $this->assertCount(1, $children);

        $this->runSubmit($account, $site, $children->first());
        $this->runPoll($account, $site, $children->first());
        $this->assertSame($submitsBefore + 1, $this->falSubmitCount());
        $this->assertCount(2, $this->charges($account));
    }

    public function test_a_null_note_override_preserves_the_plain_regenerate_behaviour(): void
    {
        [$account, $site, $base] = $this->succeedBase();

        // The 2-arg call (no override) is the classic Regenerate — still one queued asset.
        $result = Tenant::run($account, fn () => app(RegenerateProductImage::class)->handle($site, (int) $base->id));
        $this->assertSame(1, $result->queued);
    }
}
