<?php

namespace Tests\Feature\Generation;

use App\Domain\Generation\GenerateTryOnJob;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStartException;
use App\Domain\Generation\StartGeneration;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * StartGeneration — the entry point the Phase-7 widget API calls. Validates inputs,
 * stores the SOURCE photo, creates the Generation(pending), dispatches the worker job,
 * and returns a pollable handle. A double-click (same client_request_id) reuses the
 * existing generation and dispatches NO second job.
 */
class StartGenerationTest extends TestCase
{
    use GenerationTestSupport, RefreshDatabase;

    private const PHOTO = "\x89PNG\r\n\x1a\nSHOPPER";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGenerationEnv();
    }

    private function request(array $context, string $clientRequestId = 'crq-start', bool $consent = true): GenerationRequest
    {
        return new GenerationRequest(
            endUser: $context['endUser'],
            product: $context['product'],
            variant: $context['variant'],
            photoBytes: self::PHOTO,
            photoMime: 'image/png',
            userHeight: 178,
            clientRequestId: $clientRequestId,
            photoConsent: $consent,
            extraAttrs: ['angle' => 'front'],
        );
    }

    public function test_start_creates_pending_generation_stores_source_and_dispatches_job(): void
    {
        Queue::fake();
        $context = $this->makeContext();

        $result = Tenant::run($context['account'], fn () => app(StartGeneration::class)->handle($this->request($context)));

        $this->assertFalse($result->reused);
        $this->assertSame(Generation::STATUS_PENDING, $result->status);

        $generation = Tenant::run($context['account'], fn () => Generation::query()->findOrFail($result->generationId));
        $this->assertNotNull($generation->source_image_path);
        $this->assertSame(178, $generation->meta['user_height']);
        Storage::disk('s3')->assertExists($generation->source_image_path);

        // The worker job was dispatched with the explicit account_id (never inferred).
        Queue::assertPushed(GenerateTryOnJob::class, function (GenerateTryOnJob $job) use ($context, $generation) {
            return $job->accountId === (int) $context['account']->id
                && $job->generationId === (int) $generation->id;
        });
    }

    public function test_double_click_same_request_id_reuses_generation_and_dispatches_no_second_job(): void
    {
        Queue::fake();
        $context = $this->makeContext();

        $first = Tenant::run($context['account'], fn () => app(StartGeneration::class)->handle($this->request($context, 'crq-dup')));
        $second = Tenant::run($context['account'], fn () => app(StartGeneration::class)->handle($this->request($context, 'crq-dup')));

        $this->assertSame($first->generationId, $second->generationId);
        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);

        // Exactly ONE generation row, ONE job dispatched (the double-click collapsed).
        $count = Tenant::run($context['account'], fn () => Generation::query()->count());
        $this->assertSame(1, $count);
        Queue::assertPushed(GenerateTryOnJob::class, 1);
    }

    public function test_missing_photo_consent_is_a_typed_start_exception(): void
    {
        Queue::fake();
        $context = $this->makeContext();

        try {
            Tenant::run($context['account'], fn () => app(StartGeneration::class)->handle($this->request($context, 'crq-noconsent', consent: false)));
            $this->fail('Expected GenerationStartException.');
        } catch (GenerationStartException $e) {
            $this->assertSame(GenerationStartException::REASON_PHOTO_CONSENT_REQUIRED, $e->reason);
        }

        Queue::assertNothingPushed();
    }

    public function test_unconfirmed_product_cannot_start(): void
    {
        Queue::fake();
        $context = $this->makeContext();

        // A draft (unconfirmed) product is not generation-eligible.
        $draft = Tenant::run($context['account'], fn () => Product::factory()->forSite($context['site'])->create([
            'status' => Product::STATUS_DRAFT,
        ]));
        $draftVariant = Tenant::run($context['account'], fn () => ProductVariant::factory()->forProduct($draft)->create());

        $request = new GenerationRequest(
            endUser: $context['endUser'],
            product: $draft,
            variant: $draftVariant,
            photoBytes: self::PHOTO,
            photoMime: 'image/png',
            userHeight: 170,
            clientRequestId: 'crq-draft',
            photoConsent: true,
        );

        try {
            Tenant::run($context['account'], fn () => app(StartGeneration::class)->handle($request));
            $this->fail('Expected GenerationStartException.');
        } catch (GenerationStartException $e) {
            $this->assertSame(GenerationStartException::REASON_PRODUCT_NOT_CONFIRMED, $e->reason);
        }
    }

    public function test_variant_must_belong_to_the_product(): void
    {
        Queue::fake();
        $context = $this->makeContext();

        // A variant of a DIFFERENT product.
        $otherContext = $this->makeContext();
        $foreignVariant = $otherContext['variant'];

        $request = new GenerationRequest(
            endUser: $context['endUser'],
            product: $context['product'],
            variant: $foreignVariant,
            photoBytes: self::PHOTO,
            photoMime: 'image/png',
            userHeight: 170,
            clientRequestId: 'crq-mismatch',
            photoConsent: true,
        );

        try {
            Tenant::run($context['account'], fn () => app(StartGeneration::class)->handle($request));
            $this->fail('Expected GenerationStartException.');
        } catch (GenerationStartException $e) {
            $this->assertSame(GenerationStartException::REASON_VARIANT_MISMATCH, $e->reason);
        }
    }
}
