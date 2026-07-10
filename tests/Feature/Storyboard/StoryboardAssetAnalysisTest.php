<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardAssetAnalyzer;
use App\Jobs\AnalyzeStoryboardAssetJob;
use App\Models\StoryboardAsset;
use App\Models\StoryboardProject;
use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * VISION reference analysis: a multimodal model describes the ACTUAL image behind a @tag and the
 * description + detected type land on the asset — the ground truth the planning prompts reuse for
 * character fidelity. Never charges. OpenRouter is faked.
 */
class StoryboardAssetAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    private function asset(array $overrides = []): StoryboardAsset
    {
        Storage::disk('public')->put('storyboard/inputs/hero.png', "\x89PNG\r\n\x1a\nHERO");

        return StoryboardAsset::factory()->create(array_merge([
            'project_id' => StoryboardProject::factory()->create()->id,
            'tag' => 'image1',
            'type' => StoryboardAsset::TYPE_CHARACTER,
            'file_path' => 'storyboard/inputs/hero.png',
            'description' => null,
        ], $overrides));
    }

    public function test_the_job_stores_the_vision_description_and_detected_type(): void
    {
        Http::fake([self::CHAT => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'subject_type' => 'product',
                'description' => 'A matte black wireless headphone set with copper accents and an embossed logo on the left ear cup.',
            ])]]],
            'model' => 'google/gemini-3.1-pro-preview',
            'usage' => ['cost' => 0.002],
        ], 200)]);

        $asset = $this->asset();
        (new AnalyzeStoryboardAssetJob($asset->id))->handle(app(StoryboardAssetAnalyzer::class));

        $asset->refresh();
        $this->assertStringContainsString('matte black wireless headphone', (string) $asset->description);
        $this->assertSame(StoryboardAsset::TYPE_PRODUCT, $asset->type);
        $this->assertDatabaseCount('credit_ledger', 0);

        // The request is MULTIMODAL — the actual reference image rides along with the prompt.
        Http::assertSent(fn ($req): bool => str_contains((string) json_encode($req->data()), 'image_url'));
    }

    public function test_analyze_missing_covers_only_unanalyzed_assets(): void
    {
        Http::fake([self::CHAT => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'subject_type' => 'character',
                'description' => 'A tall knight with silver hair.',
            ])]]],
        ], 200)]);

        $pending = $this->asset();
        StoryboardAsset::factory()->create([
            'project_id' => $pending->project_id,
            'tag' => 'image2',
            'file_path' => 'storyboard/inputs/hero.png',
            'description' => 'Already analyzed.',
        ]);

        app(StoryboardAssetAnalyzer::class)->analyzeMissing($pending->project);

        $this->assertStringContainsString('silver hair', (string) $pending->refresh()->description);
        Http::assertSentCount(1); // the analyzed asset was NOT re-analyzed
    }

    public function test_an_analysis_failure_never_breaks_the_job(): void
    {
        Http::fake([self::CHAT => Http::response(['error' => ['message' => 'boom']], 500)]);

        $asset = $this->asset();
        (new AnalyzeStoryboardAssetJob($asset->id))->handle(app(StoryboardAssetAnalyzer::class));

        $this->assertNull($asset->refresh()->description); // pre-warm failed quietly; pipeline retries inline
    }
}
