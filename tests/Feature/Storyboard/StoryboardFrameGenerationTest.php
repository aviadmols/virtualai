<?php

namespace Tests\Feature\Storyboard;

use App\Domain\Storyboard\StoryboardFrameGenerator;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Models\AiModel;
use App\Models\AiOperation;
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
 * Per-frame image generation — the Pro-first / edit-chained design:
 *   - an anchor-LESS frame (usually frame 1) upgrades to the premium first_frame_model
 *     (OpenRouter, gemini-3-pro-image) with QUALITY + the PROJECT's aspect in the body;
 *   - every later frame runs the EDIT default (fal nano-banana) CHAINED on the previous
 *     frame's image (data-URI inlined) so the film stays visually consistent;
 *   - a regenerate edits the frame's own current image;
 *   - versions are recorded and selectable; a locked frame is untouched; never charges.
 */
class StoryboardFrameGenerationTest extends TestCase
{
    use RefreshDatabase;

    // The chained EDIT default (fal queue API: submit → status → result → download).
    private const NB_MODEL = 'fal-ai/nano-banana/edit';
    private const NB_SUBMIT = 'https://queue.fal.run/'.self::NB_MODEL;
    private const NB_REQUEST = 'req-nb1';
    private const NB_STATUS = self::NB_SUBMIT.'/requests/'.self::NB_REQUEST.'/status';
    private const NB_RESULT = self::NB_SUBMIT.'/requests/'.self::NB_REQUEST.'/response';
    private const FAL_IMAGE_URL = 'https://v3.fal.media/files/frame.png';

    // The look-setting first-frame model (OpenRouter chat completions).
    private const PRO_MODEL = 'google/gemini-3-pro-image';
    private const OR_CHAT = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.fal.api_key', 'fal-test-key');
        config()->set('services.fal.base_url', 'https://queue.fal.run');
        config()->set('services.fal.catalog_url', 'https://fal.ai/api');
        config()->set('services.fal.timeout', 30);
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        config()->set('trayon.media.disk', 's3');
        Storage::fake('s3');
        $this->seed(StoryboardPipelineSeeder::class);
        Sleep::fake();
    }

    private function fakeImage(): void
    {
        $png = "\x89PNG\r\n\x1a\nFRAME";
        $dataUrl = 'data:image/png;base64,'.base64_encode($png);

        Http::fake([
            self::OR_CHAT => Http::response([
                'id' => 'or-1',
                'model' => self::PRO_MODEL,
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'images' => [['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]],
                ]]],
            ], 200),
            self::NB_STATUS => Http::response(['status' => 'COMPLETED'], 200),
            self::NB_RESULT => Http::response(['images' => [['url' => self::FAL_IMAGE_URL, 'content_type' => 'image/png']]], 200),
            self::NB_SUBMIT => Http::response(['request_id' => self::NB_REQUEST], 200),
            self::FAL_IMAGE_URL => Http::response($png, 200),
            '*' => Http::response($png, 200), // signed input-image fetches (data-URI inlining)
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

    public function test_an_anchorless_frame_runs_the_premium_first_frame_model_with_quality_and_project_aspect(): void
    {
        $this->fakeImage();
        $frame = $this->frame([], ['aspect_ratio' => '9:16']);

        app(StoryboardFrameGenerator::class)->generate($frame);

        $frame->refresh();
        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->status);
        Storage::disk('s3')->assertExists($frame->image_path);

        // The look-setter went to OpenRouter's premium model — with the configured QUALITY and
        // the PROJECT's aspect (not the operation's 16:9) actually in the body.
        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::OR_CHAT) {
                return false;
            }
            $body = (array) json_decode((string) $request->body(), true);

            return ($body['model'] ?? null) === self::PRO_MODEL
                && ($body['quality'] ?? null) === 'high'
                && ($body['aspect_ratio'] ?? null) === '9:16';
        });

        $this->assertSame(1, $frame->versions()->where('is_selected', true)->count());
        $this->assertSame(self::PRO_MODEL, $frame->versions()->first()->model);
        // Flat-rate: the catalogued first-frame model's price hint is the recorded cost.
        $this->assertSame(120_000, $frame->image_cost_micro_usd);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_a_later_frame_chains_on_the_previous_frames_image_via_the_edit_model(): void
    {
        $this->fakeImage();
        $second = $this->chainedFrame(['aspect_ratio' => '9:16']);

        app(StoryboardFrameGenerator::class)->generate($second);

        $this->assertSame(StoryboardFrame::STATUS_READY, $second->refresh()->status);

        // The chained frame ran the EDIT default with the ANCHOR inlined as a data URI and the
        // project aspect mapped onto fal's image_size enum.
        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::NB_SUBMIT) {
                return false;
            }
            $body = (array) json_decode((string) $request->body(), true);

            return str_starts_with((string) ($body['image_url'] ?? ''), 'data:image/')
                && ($body['image_size'] ?? null) === 'portrait_16_9';
        });

        // The continuity lead labels the anchor as the PREVIOUS SHOT.
        $prompt = (string) $second->versions()->first()->prompt;
        $this->assertStringContainsString('PREVIOUS SHOT', $prompt);
        $this->assertSame(self::NB_MODEL, $second->versions()->first()->model);
    }

    public function test_a_missing_first_frame_catalog_row_falls_back_to_the_default_model(): void
    {
        $this->fakeImage();
        AiModel::query()
            ->where('operation_key', AiOperation::KEY_STORYBOARD_FRAME_IMAGE)
            ->where('model_id', self::PRO_MODEL)
            ->update(['is_active' => false]);

        $frame = $this->frame();
        app(StoryboardFrameGenerator::class)->generate($frame);

        // No catalogued first-frame model -> the edit default generated it (never a hard fail).
        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->refresh()->status);
        $this->assertSame(self::NB_MODEL, $frame->versions()->first()->model);
    }

    public function test_regenerate_edits_the_frames_own_image_and_adds_a_selected_version(): void
    {
        $this->fakeImage();
        $frame = $this->frame();
        $generator = app(StoryboardFrameGenerator::class);

        $generator->generate($frame);              // first run: the look-setter
        $generator->generate($frame->refresh());   // regenerate: EDIT the current image

        $frame->refresh();
        $this->assertSame(2, $frame->versions()->count());
        $selected = $frame->versions()->where('is_selected', true)->get();
        $this->assertCount(1, $selected);
        $this->assertSame(2, $selected->first()->version_number);

        // The regenerate carried the frame's own image into the EDIT model.
        Http::assertSent(fn ($request): bool => $request->url() === self::NB_SUBMIT
            && str_contains((string) $request->body(), 'image_url'));
        $this->assertStringContainsString('CURRENT version', (string) $frame->versions()->where('version_number', 2)->first()->prompt);
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

    public function test_reference_tags_ride_along_to_the_look_setting_model(): void
    {
        $this->fakeImage();
        $frame = $this->frame(['reference_tags' => ['hero']]);
        $frame->project->assets()->create(['tag' => 'hero', 'type' => 'character', 'file_path' => 'storyboard/inputs/hero.png']);
        Storage::disk('s3')->put('storyboard/inputs/hero.png', "\x89PNG\r\n\x1a\nHERO");

        app(StoryboardFrameGenerator::class)->generate($frame);

        $this->assertSame(StoryboardFrame::STATUS_READY, $frame->refresh()->status);
        // An anchor-less frame still upgrades to the premium model — the @hero identity
        // reference rides as an image content part.
        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::OR_CHAT) {
                return false;
            }
            $body = (array) json_decode((string) $request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];

            return collect(is_array($content) ? $content : [])->contains(
                fn ($part): bool => ($part['type'] ?? '') === 'image_url',
            );
        });
        $this->assertSame(self::PRO_MODEL, $frame->versions()->first()->model);
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
