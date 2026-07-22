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
 * Per-frame image generation — the Kling-native chained design:
 *   - Kling consumes exactly ONE reference image, so each frame attaches the strongest
 *     continuity signal available: its own image (regenerate) → the previous frame (the
 *     chain — identity propagates through it) → the first @tag identity reference;
 *   - the prompt lead names exactly the image Kling receives (never phantom attachments);
 *   - a refusal on the primary (kling-v3) falls back to the SAME-provider kling-v2-1;
 *   - versions are recorded and selectable; a locked frame is untouched; never charges.
 * The Kling image API (submit → poll → result-url download) is faked.
 */
class StoryboardFrameGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-singapore.klingai.com';
    private const SUBMIT = self::BASE.'/v1/images/generations';
    private const TASK = 'sb-task-1';
    private const QUERY = self::SUBMIT.'/'.self::TASK;
    private const IMAGE_URL = 'https://cdn.klingai.test/frame.png';

    private const PNG = "\x89PNG\r\n\x1a\nFRAME";

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.kling.api_key', 'api-key-kling-test');
        config()->set('services.kling.base_url', self::BASE);
        config()->set('services.kling.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    /** One Kling envelope. @param array<string,mixed> $extra */
    private function task(string $status, array $extra = []): array
    {
        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => 'req-1',
            'data' => array_merge(['task_id' => self::TASK, 'task_status' => $status], $extra),
        ];
    }

    private function succeeded(): array
    {
        return $this->task('succeed', [
            'task_result' => ['images' => [['index' => 0, 'url' => self::IMAGE_URL]]],
        ]);
    }

    private function fakeImage(): void
    {
        Http::fake([
            self::QUERY => Http::response($this->succeeded(), 200),
            self::SUBMIT => Http::response($this->task('submitted'), 200),
            self::IMAGE_URL => Http::response(self::PNG, 200),
            '*' => Http::response(self::PNG, 200), // signed input-image fetches (base64 inlining)
        ]);
    }

    private function frame(array $overrides = [], array $projectState = []): StoryboardFrame
    {
        $project = StoryboardProject::factory()->create($projectState);

        return StoryboardFrame::factory()->create(array_merge([
            'project_id' => $project->id,
            'frame_number' => 1,
            'image_prompt' => 'Cinematic pool party, bright daylight',
        ], $overrides));
    }

    /** A second frame in the same project, with frame 1 already carrying a stored image. */
    private function chainedFrame(array $projectState = []): StoryboardFrame
    {
        $first = $this->frame([
            'status' => StoryboardFrame::STATUS_READY,
            'image_path' => 'storyboard/frames/first.png',
        ], $projectState);
        Storage::disk('s3')->put('storyboard/frames/first.png', "\x89PNG\r\n\x1a\nFIRST");

        return StoryboardFrame::factory()->create([
            'project_id' => $first->project_id,
            'frame_number' => 2,
            'image_prompt' => 'Scene beat 2: the rescue',
        ]);
    }

    /** The decoded submit body of the LAST request to the generations endpoint. */
    private function submitBody(): array
    {
        $body = [];
        Http::assertSent(function ($request) use (&$body): bool {
            if ($request->url() === self::SUBMIT) {
                $body = (array) json_decode((string) $request->body(), true);
            }

            return true;
        });

        return $body;
    }

    public function test_a_referenceless_frame_generates_on_kling_with_the_project_aspect(): void
    {
        $this->fakeImage();
        $frame = $this->frame([], ['aspect_ratio' => '9:16']);

        app(StoryboardFrameGenerator::class)->generate($frame);

        $frame->refresh();
        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->status);
        Storage::disk('s3')->assertExists($frame->image_path);

        $body = $this->submitBody();
        $this->assertSame('kling-v3', $body['model_name'] ?? null);
        $this->assertSame('9:16', $body['aspect_ratio'] ?? null);
        // No input image → no reference knobs, no raw model id, no sampler leak.
        $this->assertArrayNotHasKey('image', $body);
        $this->assertArrayNotHasKey('image_reference', $body);
        $this->assertArrayNotHasKey('model', $body);
        $this->assertArrayNotHasKey('temperature', $body);

        $this->assertSame(1, $frame->versions()->where('is_selected', true)->count());
        $this->assertSame('kling-v3', $frame->versions()->first()->model);
        // Flat-rate: the operation's estimate is the recorded cost (no inline USD in the fake).
        $this->assertSame(28_000, $frame->image_cost_micro_usd);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_a_later_frame_chains_on_the_previous_frames_image_as_the_single_reference(): void
    {
        $this->fakeImage();
        $second = $this->chainedFrame();

        app(StoryboardFrameGenerator::class)->generate($second);

        $this->assertSame(StoryboardFrame::STATUS_READY, $second->refresh()->status);

        $body = $this->submitBody();
        // The anchor rides as Kling's single `image` reference — RAW base64, no data: prefix.
        $this->assertNotSame('', (string) ($body['image'] ?? ''));
        $this->assertStringNotContainsString('data:', (string) $body['image']);
        // The reference-tuning knob rides ONLY alongside an actual reference.
        $this->assertSame('subject', $body['image_reference'] ?? null);

        // The continuity lead names exactly what Kling received: the PREVIOUS SHOT.
        $prompt = (string) $second->versions()->first()->prompt;
        $this->assertStringContainsString('PREVIOUS SHOT', $prompt);
        $this->assertStringNotContainsString('Any remaining attached images', $prompt);
    }

    public function test_a_refusal_on_the_primary_falls_back_to_the_same_provider_fallback(): void
    {
        // kling-v3 submits then terminally FAILS (the refusal class); kling-v2-1 succeeds.
        Http::fake([
            self::QUERY => Http::sequence()
                ->push($this->task('failed', ['task_status_msg' => 'content policy']), 200)
                ->push($this->succeeded(), 200),
            self::SUBMIT => Http::response($this->task('submitted'), 200),
            self::IMAGE_URL => Http::response(self::PNG, 200),
            '*' => Http::response(self::PNG, 200),
        ]);

        $frame = $this->frame();
        app(StoryboardFrameGenerator::class)->generate($frame);

        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->refresh()->status);
        $this->assertSame('kling-v2-1', $frame->versions()->first()->model);

        // Both models were actually submitted, in order.
        $models = [];
        Http::assertSent(function ($request) use (&$models): bool {
            if ($request->url() === self::SUBMIT) {
                $models[] = json_decode((string) $request->body(), true)['model_name'] ?? null;
            }

            return true;
        });
        $this->assertSame(['kling-v3', 'kling-v2-1'], $models);
    }

    public function test_regenerate_edits_the_frames_own_image_and_adds_a_selected_version(): void
    {
        $this->fakeImage();
        $frame = $this->frame();
        $generator = app(StoryboardFrameGenerator::class);

        $generator->generate($frame);
        $generator->generate($frame->refresh());   // regenerate: EDIT the current image

        $frame->refresh();
        $this->assertSame(2, $frame->versions()->count());
        $selected = $frame->versions()->where('is_selected', true)->get();
        $this->assertCount(1, $selected);
        $this->assertSame(2, $selected->first()->version_number);

        // The regenerate carried the frame's own image as the single reference, and the lead
        // names it as the CURRENT version to edit.
        $this->assertStringContainsString('CURRENT version', (string) $frame->versions()->where('version_number', 2)->first()->prompt);
    }

    public function test_a_tag_reference_becomes_the_single_reference_on_an_anchorless_frame(): void
    {
        $this->fakeImage();
        $frame = $this->frame(['reference_tags' => ['hero']]);
        $frame->project->assets()->create(['tag' => 'hero', 'type' => 'character', 'file_path' => 'storyboard/inputs/hero.png']);
        Storage::disk('s3')->put('storyboard/inputs/hero.png', "\x89PNG\r\n\x1a\nHERO");

        app(StoryboardFrameGenerator::class)->generate($frame);

        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->refresh()->status);

        $body = $this->submitBody();
        $this->assertNotSame('', (string) ($body['image'] ?? ''));
        $this->assertSame('subject', $body['image_reference'] ?? null);

        // The lead names the identity reference — not a previous shot that wasn't attached.
        $prompt = (string) $frame->versions()->first()->prompt;
        $this->assertStringContainsString('identity reference', $prompt);
        $this->assertStringNotContainsString('PREVIOUS SHOT', $prompt);
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
