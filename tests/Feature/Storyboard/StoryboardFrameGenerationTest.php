<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardFrameGenerator;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Database\Seeders\StoryboardPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Per-frame image generation: the frame-image operation + runner produce an image, store it, and
 * record a selected VERSION (history kept). Regenerate adds a version; selecting an older version
 * swaps the frame's image; a locked frame is untouched. Never charges. OpenRouter is faked.
 */
class StoryboardFrameGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    private function fakeImage(string $marker = 'FRAME'): void
    {
        $png = "\x89PNG\r\n\x1a\n".$marker;
        $dataUrl = 'data:image/png;base64,'.base64_encode($png);

        Http::fake([self::CHAT => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'images' => [['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]]]]],
            'model' => 'google/gemini-3.1-flash-image',
            'usage' => ['cost' => 0.04],
        ], 200)]);
    }

    private function frame(array $overrides = []): StoryboardFrame
    {
        $project = StoryboardProject::factory()->create();

        return StoryboardFrame::factory()->create(array_merge([
            'project_id' => $project->id,
            'image_prompt' => 'Cinematic pool party, bright daylight',
        ], $overrides));
    }

    public function test_generating_a_frame_stores_the_image_and_records_a_selected_version(): void
    {
        $this->fakeImage();
        $frame = $this->frame();

        app(StoryboardFrameGenerator::class)->generate($frame);

        $frame->refresh();
        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->status);
        $this->assertNotNull($frame->image_path);
        Storage::disk('s3')->assertExists($frame->image_path);

        $this->assertSame(1, $frame->versions()->count());
        $this->assertSame(1, $frame->versions()->where('is_selected', true)->count());
        // Cost recorded from the provider's inline cost (0.04 USD → 40_000 micro).
        $this->assertSame(40_000, $frame->image_cost_micro_usd);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_regenerate_adds_a_new_selected_version(): void
    {
        $this->fakeImage();
        $frame = $this->frame();
        $generator = app(StoryboardFrameGenerator::class);

        $generator->generate($frame);
        $generator->generate($frame->refresh());

        $frame->refresh();
        $this->assertSame(2, $frame->versions()->count());
        $selected = $frame->versions()->where('is_selected', true)->get();
        $this->assertCount(1, $selected);
        $this->assertSame(2, $selected->first()->version_number);
    }

    public function test_selecting_an_older_version_swaps_the_frame_image(): void
    {
        $this->fakeImage();
        $frame = $this->frame();
        $generator = app(StoryboardFrameGenerator::class);

        $generator->generate($frame);
        $v1 = $frame->refresh()->versions()->first();
        $generator->generate($frame->refresh());

        $generator->selectVersion($frame->refresh(), $v1);

        $frame->refresh();
        $this->assertTrue($frame->versions()->find($v1->id)->is_selected);
        $this->assertSame($v1->image_path, $frame->image_path);
    }

    public function test_a_locked_frame_is_not_regenerated(): void
    {
        $this->fakeImage();
        $frame = $this->frame(['is_locked' => true, 'status' => StoryboardFrame::STATUS_READY]);

        app(StoryboardFrameGenerator::class)->generate($frame);

        $this->assertSame(0, $frame->refresh()->versions()->count());
        Http::assertNothingSent();
    }

    public function test_the_job_generates_a_frame(): void
    {
        $this->fakeImage();
        $frame = $this->frame();

        (new GenerateStoryboardFrameJob($frame->id))->handle(app(StoryboardFrameGenerator::class));

        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->refresh()->status);
    }
}
