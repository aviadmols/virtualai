<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardFrameGenerator;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Models\Prompt;
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
 * swaps the frame's image; a locked frame is untouched. Never charges. The frame-image step runs
 * on fal.ai (Krea 2 Turbo) — the fal queue API (submit → status → result → download) is faked.
 */
class StoryboardFrameGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const FAL_MODEL = 'fal-ai/krea-2/turbo';
    private const FAL_SUBMIT = 'https://queue.fal.run/'.self::FAL_MODEL;
    private const FAL_REQUEST = 'req-sb1';
    private const FAL_STATUS = self::FAL_SUBMIT.'/requests/'.self::FAL_REQUEST.'/status';
    private const FAL_RESULT = self::FAL_SUBMIT.'/requests/'.self::FAL_REQUEST;
    private const FAL_IMAGE_URL = 'https://v3.fal.media/files/frame.png';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.fal.api_key', 'fal-test-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    private function fakeImage(string $marker = 'FRAME'): void
    {
        $png = "\x89PNG\r\n\x1a\n".$marker;

        Http::fake([
            self::FAL_STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::FAL_RESULT => Http::response(['images' => [['url' => self::FAL_IMAGE_URL, 'content_type' => 'image/png']]], 200),
            self::FAL_SUBMIT => Http::response(['request_id' => self::FAL_REQUEST], 200),
            self::FAL_IMAGE_URL => Http::response($png, 200),
            '*' => Http::response($png, 200), // signed input-image fetches (data-URI inlining)
        ]);
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
        // fal is flat-rate (no inline USD) — the operation's estimate is the recorded cost.
        $this->assertSame(20_000, $frame->image_cost_micro_usd);
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

    public function test_the_generation_prompt_is_led_by_the_operations_system_prompt(): void
    {
        $this->fakeImage();
        $frame = $this->frame();

        app(StoryboardFrameGenerator::class)->generate($frame);

        // The admin-editable frame-image system prompt is LIVE config: it leads the effective
        // prompt, followed by the frame's own image_prompt.
        $system = (string) Prompt::query()
            ->where('operation_key', 'storyboard_frame_image')
            ->value('system_prompt');

        $recorded = (string) $frame->refresh()->versions()->first()->prompt;
        $this->assertNotSame('', trim($system));
        $this->assertStringStartsWith($system, $recorded);
        $this->assertStringContainsString('Cinematic pool party, bright daylight', $recorded);
    }

    public function test_regenerate_feeds_the_current_image_so_it_edits_not_reinvents(): void
    {
        $this->fakeImage();
        $frame = $this->frame(); // no @refs in the prompt, so the only image input can be the current one
        $generator = app(StoryboardFrameGenerator::class);

        $generator->generate($frame);              // first run: text-to-image, no input image
        $generator->generate($frame->refresh());   // regenerate: the current image must be fed in

        // At least one request (the regenerate) carried an input image, so the model EDITS the
        // existing frame (keeps composition/characters/style) instead of inventing a new one.
        Http::assertSent(fn ($request): bool => str_contains((string) json_encode($request->data()), 'image_url'));
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
