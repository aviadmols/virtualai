<?php

namespace Tests\Feature\Banners;

use App\Domain\Credits\IdempotencyKey;
use App\Domain\Generation\GenerateBannerJob;
use App\Domain\Media\MediaStorage;
use App\Models\Account;
use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Generation\GenerationTestSupport;
use Tests\TestCase;

/**
 * Banner @-product tagging. A @product_{id} in the brief grounds the banner in that REAL
 * product: its image is attached to the model call as a reference AND its name/facts are woven
 * into the prompt (the raw token is swapped for the product name). Tenant-safe — a foreign /
 * unknown id leaks nothing (MentionResolver is site-scoped + fail-closed) — and an uploaded
 * reference still wins as the visual reference. OpenRouter HTTP + the media disk are faked.
 */
class BannerProductMentionTest extends TestCase
{
    use GenerationTestSupport, RefreshDatabase;

    private const PRODUCT_IMAGE = 'https://cdn.example.com/mention-product.jpg';

    private const PRODUCT_NAME = 'Sunset Linen Jacket';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGenerationEnv(); // seeds the control plane (incl. banner_generation) + fakes
    }

    /** @return array{account: Account, site: Site, banner: Banner} */
    private function makeBannerContext(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $banner = Tenant::run($account, fn () => Banner::factory()->forSite($site)->create());

        return compact('account', 'site', 'banner');
    }

    private function makePendingAsset(array $context, string $brief, string $clientRequestId = 'crq-mention'): BannerAsset
    {
        $banner = $context['banner'];

        $key = IdempotencyKey::forBanner(
            accountId: (int) $banner->account_id,
            siteId: (int) $banner->site_id,
            bannerId: (int) $banner->getKey(),
            clientRequestId: $clientRequestId,
        );

        return Tenant::run($context['account'], fn () => BannerAsset::factory()
            ->forBanner($banner, $clientRequestId)
            ->create(['idempotency_key' => $key, 'status' => BannerAsset::STATUS_PENDING, 'brief' => $brief]));
    }

    private function makeProduct(array $context, array $overrides = []): Product
    {
        return Tenant::run($context['account'], fn () => Product::factory()
            ->forSite($context['site'])
            ->confirmed()
            ->create(array_merge([
                'name' => self::PRODUCT_NAME,
                'main_image_url' => self::PRODUCT_IMAGE,
            ], $overrides)));
    }

    private function runJob(array $context, BannerAsset $asset): void
    {
        (new GenerateBannerJob(
            (int) $context['account']->id,
            (int) $context['site']->id,
            (int) $asset->id,
        ))->handle();
    }

    /** True when a sent request's JSON body carries $needle (slashes unescaped so URLs match). */
    private function bodyHas($request, string $needle): bool
    {
        return str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES), $needle);
    }

    public function test_a_tagged_product_grounds_the_banner_in_its_image_and_name(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $context = $this->makeBannerContext();
        $product = $this->makeProduct($context);

        $asset = $this->makePendingAsset($context, 'A summer sale hero for @product_'.$product->id.'.');
        $this->runJob($context, $asset);

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_SUCCEEDED, $asset->status);

        // The product's own image was attached to the model call as a reference input.
        Http::assertSent(fn ($request) => $this->bodyHas($request, self::PRODUCT_IMAGE));

        // The prompt snapshot swapped @product_{id} for the real product name (token gone).
        $snapshot = (string) ($asset->meta[BannerAsset::META_PROMPT_SNAPSHOT] ?? '');
        $this->assertStringContainsString(self::PRODUCT_NAME, $snapshot);
        $this->assertStringNotContainsString('@product_', $snapshot);
    }

    public function test_a_foreign_product_tag_leaks_nothing_and_still_generates(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $mine = $this->makeBannerContext();
        $foreign = $this->makeBannerContext();

        // Another shop's product — its image must NEVER reach my banner's model call.
        $foreignProduct = $this->makeProduct($foreign, [
            'name' => 'Foreign Coat',
            'main_image_url' => 'https://cdn.example.com/foreign.jpg',
        ]);

        $asset = $this->makePendingAsset($mine, 'Banner referencing @product_'.$foreignProduct->id.'.');
        $this->runJob($mine, $asset);

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_SUCCEEDED, $asset->status);

        // Fail-closed: the foreign product's image reaches no request.
        Http::assertNotSent(fn ($request) => $this->bodyHas($request, 'foreign.jpg'));

        // No product to substitute, so the unknown token simply stays as literal brief text.
        $snapshot = (string) ($asset->meta[BannerAsset::META_PROMPT_SNAPSHOT] ?? '');
        $this->assertStringContainsString('@product_'.$foreignProduct->id, $snapshot);
    }

    public function test_an_uploaded_reference_wins_over_a_tag_but_the_text_is_still_grounded(): void
    {
        $this->fakeOpenRouterSuccess(costUsd: 0.40);
        $context = $this->makeBannerContext();
        $product = $this->makeProduct($context);

        $asset = $this->makePendingAsset($context, 'Use the attached brand shot for @product_'.$product->id.'.');

        // The merchant's explicit uploaded reference — it must win as the VISUAL reference.
        Tenant::run($context['account'], function () use ($context, $asset): void {
            $stored = app(MediaStorage::class)->storeBannerSource(
                (int) $context['account']->id,
                (int) $context['site']->id,
                (int) $asset->id,
                "\x89PNG\r\n\x1a\nBRAND-REFERENCE",
                'image/png',
            );
            $asset->forceFill(['source_image_path' => $stored->path])->save();
        });

        $this->runJob($context, $asset);

        $asset->refresh();
        $this->assertSame(BannerAsset::STATUS_SUCCEEDED, $asset->status);

        // The upload took priority: the product's own image is NOT the reference.
        Http::assertNotSent(fn ($request) => $this->bodyHas($request, self::PRODUCT_IMAGE));

        // ...but the tagged product still grounds the TEXT (its name is woven into the prompt).
        $snapshot = (string) ($asset->meta[BannerAsset::META_PROMPT_SNAPSHOT] ?? '');
        $this->assertStringContainsString(self::PRODUCT_NAME, $snapshot);
    }
}
