<?php

namespace Tests\Feature\Leads;

use App\Domain\Leads\LeadAttempt;
use App\Domain\Leads\LeadAttemptHistory;
use App\Domain\Leads\LeadsExporter;
use App\Domain\Media\MediaStorage;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Leads export (A6) + attempt history (A7): the CSV carries the right account-scoped
 * rows in the frozen column order, and the attempt history returns immutable per-row
 * DTOs with signed thumbnails / purged flags.
 */
class LeadsExportAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
    }

    public function test_export_returns_header_and_one_row_per_lead_in_frozen_order(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($site): void {
            EndUser::factory()->forSite($site)->registered()->create([
                'full_name' => 'Dana Levi',
                'email' => 'dana@example.com',
                'phone' => '+972500000000',
                'status' => EndUser::STATUS_GENERATED,
                'generations_used' => 3,
            ]);
            EndUser::factory()->forSite($site)->create([
                'full_name' => null,
                'email' => null,
                'phone' => null,
                'status' => EndUser::STATUS_NEW,
                'generations_used' => 0,
            ]);
        });

        $rows = app(LeadsExporter::class)->rows($account);

        // Header is the frozen COLUMNS contract.
        $this->assertSame(LeadsExporter::COLUMNS, $rows[0]);
        // 2 leads -> header + 2 rows.
        $this->assertCount(3, $rows);

        // The registered lead's row (newest-first ordering puts the 2nd-created first;
        // assert by content rather than position to stay robust).
        $byEmail = collect($rows)->skip(1)->keyBy(fn (array $r) => $r[1]);

        $dana = $byEmail->get('dana@example.com');
        $this->assertNotNull($dana);
        $this->assertSame('Dana Levi', $dana[0]);
        $this->assertSame('+972500000000', $dana[2]);
        $this->assertSame(EndUser::STATUS_GENERATED, $dana[3]);
        $this->assertSame('yes', $dana[4]); // registered
        $this->assertSame('3', $dana[5]);   // generations_used

        // The anonymous lead: nulls become empty strings, registered=no.
        $anon = collect($rows)->skip(1)->first(fn (array $r) => $r[1] === '');
        $this->assertSame('', $anon[0]);
        $this->assertSame('', $anon[2]);
        $this->assertSame('no', $anon[4]);
        $this->assertSame('0', $anon[5]);
    }

    public function test_export_can_be_narrowed_to_a_single_site(): void
    {
        $account = Account::factory()->create();
        $siteA = Site::factory()->forAccount($account)->create();
        $siteB = Site::factory()->forAccount($account)->create();

        Tenant::run($account, function () use ($siteA, $siteB): void {
            EndUser::factory()->forSite($siteA)->count(2)->create();
            EndUser::factory()->forSite($siteB)->count(3)->create();
        });

        $this->assertCount(1 + 2, app(LeadsExporter::class)->rows($account, $siteA));
        $this->assertCount(1 + 3, app(LeadsExporter::class)->rows($account, $siteB));
        $this->assertCount(1 + 5, app(LeadsExporter::class)->rows($account)); // whole account
    }

    public function test_download_streams_a_csv_with_attachment_headers(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        Tenant::run($account, fn () => EndUser::factory()->forSite($site)->create());

        $response = app(LeadsExporter::class)->download($account);

        $this->assertSame('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('account-'.$account->id, (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('full_name,email,phone,status', $csv);
    }

    public function test_attempt_history_returns_newest_first_with_signed_thumbnail(): void
    {
        $context = $this->makeLeadWithAttempts();

        /** @var \Illuminate\Support\Collection<int,LeadAttempt> $history */
        $history = app(LeadAttemptHistory::class)->for($context['endUser']);

        // 2 attempts, newest first.
        $this->assertCount(2, $history);
        $this->assertInstanceOf(LeadAttempt::class, $history->first());

        $succeeded = $history->firstWhere('status', Generation::STATUS_SUCCEEDED);
        $this->assertNotNull($succeeded);
        $this->assertTrue($succeeded->succeeded());
        $this->assertSame('Red Sneaker', $succeeded->productName);
        $this->assertSame(['color' => 'Red', 'size' => 'M'], $succeeded->variantOptions);
        $this->assertNotNull($succeeded->resultThumbnailUrl, 'a stored result yields a signed URL');
        $this->assertFalse($succeeded->purged);

        $failed = $history->firstWhere('status', Generation::STATUS_FAILED);
        $this->assertNotNull($failed);
        $this->assertTrue($failed->failed());
        $this->assertSame('ai_call_failed', $failed->failureCode);
        $this->assertNull($failed->resultThumbnailUrl);
    }

    public function test_attempt_history_flags_a_purged_result(): void
    {
        $context = $this->makeLeadWithAttempts();

        // Simulate retention: delete the result bytes for the succeeded generation but
        // KEEP the generation row (and the ledger). The path stays, the object is gone.
        $succeeded = Tenant::run($context['account'], fn () => Generation::query()
            ->where('end_user_id', $context['endUser']->id)
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->firstOrFail());

        app(MediaStorage::class)->delete($succeeded->result_image_path);

        $attempt = app(LeadAttemptHistory::class)->for($context['endUser'])
            ->firstWhere('status', Generation::STATUS_SUCCEEDED);

        $this->assertTrue($attempt->purged, 'a succeeded attempt with no result bytes is purged');
        $this->assertNull($attempt->resultThumbnailUrl);
    }

    /**
     * A lead with two attempts under one account: one succeeded (with a stored result
     * image) and one failed.
     *
     * @return array{account: Account, site: Site, endUser: EndUser}
     */
    private function makeLeadWithAttempts(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $endUser = Tenant::run($account, function () use ($account, $site) {
            $endUser = EndUser::factory()->forSite($site)->create();
            $product = Product::factory()->forSite($site)->confirmed()->create([
                'name' => 'Red Sneaker',
            ]);
            $variant = ProductVariant::factory()->forProduct($product)->create([
                'options' => ['color' => 'Red', 'size' => 'M'],
            ]);

            $succeeded = Generation::factory()->forContext($endUser, $product, $variant, 'crq-ok')
                ->create(['status' => Generation::STATUS_SUCCEEDED]);

            // Store a result image so the signed-URL path is exercised.
            $stored = app(MediaStorage::class)->storeResult(
                (int) $account->id, (int) $site->id, (int) $succeeded->id, 'RESULT-BYTES', 'image/png',
            );
            $succeeded->forceFill(['result_image_path' => $stored->path])->save();

            Generation::factory()->forContext($endUser, $product, $variant, 'crq-fail')
                ->create(['status' => Generation::STATUS_FAILED, 'failure_code' => 'ai_call_failed']);

            return $endUser;
        });

        return ['account' => $account, 'site' => $site, 'endUser' => $endUser];
    }
}
