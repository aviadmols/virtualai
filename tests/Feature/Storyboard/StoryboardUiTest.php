<?php

namespace Tests\Feature\Storyboard;

use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\CreateStoryboardProject;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\EditStoryboardProject;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\ListStoryboardProjects;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\StoryboardBuilder;
use App\Jobs\AnalyzeStoryboardAssetJob;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Jobs\RunStoryboardPipelineJob;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use App\Models\User;
use Database\Seeders\StoryboardPipelineSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Storyboard admin surface: the list + builder pages render, and the builder's frame actions
 * (generate, approve, lock) mutate only THIS project's frames and dispatch generation off-request.
 */
class StoryboardUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
        config()->set('trayon.media.disk', 's3');
    }

    public function test_the_list_page_renders(): void
    {
        StoryboardProject::factory()->create();
        Livewire::test(ListStoryboardProjects::class)->assertOk();
    }

    public function test_creating_a_project_auto_numbers_the_reference_images(): void
    {
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');
        Storage::disk('public')->put('storyboard/inputs/a.png', 'a');
        Storage::disk('public')->put('storyboard/inputs/b.png', 'b');

        Livewire::test(CreateStoryboardProject::class)
            ->fillForm([
                'title' => 'Pool Party',
                'story_idea' => 'A trailer featuring @image1 at @image2',
                'duration_seconds' => 9,
                'frame_interval_seconds' => 3,
                'aspect_ratio' => '16:9',
                'reference_uploads' => ['storyboard/inputs/a.png', 'storyboard/inputs/b.png'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = StoryboardProject::query()->firstOrFail();
        // No manual naming: the pool is auto-numbered @image1, @image2 in upload order.
        $this->assertSame(['image1', 'image2'], $project->assets()->orderBy('id')->pluck('tag')->all());
        $this->assertSame(
            ['storyboard/inputs/a.png', 'storyboard/inputs/b.png'],
            $project->assets()->orderBy('id')->pluck('file_path')->all(),
        );
        $this->assertSame(auth()->id(), $project->created_by);
    }

    public function test_editing_renumbers_the_reference_images_in_the_new_order(): void
    {
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');

        $project = StoryboardProject::factory()->create();
        $project->assets()->create(['tag' => 'image1', 'type' => 'character', 'file_path' => 'storyboard/inputs/a.png']);

        Livewire::test(EditStoryboardProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['reference_uploads' => ['storyboard/inputs/b.png', 'storyboard/inputs/a.png']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['image1', 'image2'], $project->assets()->orderBy('id')->pluck('tag')->all());
        $this->assertSame(
            ['storyboard/inputs/b.png', 'storyboard/inputs/a.png'],
            $project->assets()->orderBy('id')->pluck('file_path')->all(),
        );
    }

    public function test_saving_references_keeps_their_vision_analysis_and_analyzes_only_new_images(): void
    {
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');
        Bus::fake([AnalyzeStoryboardAssetJob::class]);

        $project = StoryboardProject::factory()->create();
        $project->assets()->create(['tag' => 'image1', 'type' => 'product', 'file_path' => 'storyboard/inputs/a.png', 'description' => 'ANALYZED: a red sneaker']);

        Livewire::test(EditStoryboardProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['reference_uploads' => ['storyboard/inputs/b.png', 'storyboard/inputs/a.png']])
            ->call('save')
            ->assertHasNoFormErrors();

        $assets = $project->assets()->orderBy('id')->get();
        // The re-uploaded image keeps its analysis (description + detected type) despite renumbering.
        $this->assertSame('ANALYZED: a red sneaker', $assets->firstWhere('file_path', 'storyboard/inputs/a.png')->description);
        $this->assertSame('product', $assets->firstWhere('file_path', 'storyboard/inputs/a.png')->type);
        // Only the NEW image is queued for vision analysis.
        Bus::assertDispatched(AnalyzeStoryboardAssetJob::class, 1);
    }

    public function test_dialogue_is_saved_per_frame_and_limited_to_the_frames_seconds(): void
    {
        $project = StoryboardProject::factory()->create();
        $frame = StoryboardFrame::factory()->create([
            'project_id' => $project->id,
            'start_second' => 0,
            'end_second' => 3, // 3s × 15 chars/s = 45 chars, floored at 30 → limit 45
        ]);

        $component = Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])
            ->call('startDialogue', $frame->id)
            ->set('dialogueText', 'שלום, ברוכים הבאים למסיבה!')
            ->call('saveDialogue');

        $this->assertSame('שלום, ברוכים הבאים למסיבה!', $frame->refresh()->dialogue);

        // A line that cannot FIT the frame's seconds is rejected (the video timing must survive).
        $component->call('startDialogue', $frame->id)
            ->set('dialogueText', str_repeat('a', 46))
            ->call('saveDialogue');

        $this->assertSame('שלום, ברוכים הבאים למסיבה!', $frame->refresh()->dialogue);
    }

    public function test_the_form_generate_action_creates_and_runs_the_pipeline(): void
    {
        Bus::fake();

        Livewire::test(CreateStoryboardProject::class)
            ->fillForm([
                'title' => 'Runner',
                'story_idea' => 'A short film with @image1',
                'duration_seconds' => 9,
                'frame_interval_seconds' => 3,
                'aspect_ratio' => '16:9',
            ])
            ->call('submitAndGenerate', true);

        $project = StoryboardProject::query()->firstOrFail();
        $this->assertSame(StoryboardProject::STATUS_RUNNING, $project->status);
        $this->assertGreaterThan(0, $project->stepRuns()->where('status', StoryboardStepRun::STATUS_PENDING)->count());
        Bus::assertDispatched(RunStoryboardPipelineJob::class);
    }

    public function test_the_builder_page_renders(): void
    {
        $project = StoryboardProject::factory()->create();
        StoryboardFrame::factory()->create(['project_id' => $project->id]);

        Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])->assertOk();
    }

    public function test_the_edit_page_renders_the_story_composer(): void
    {
        $project = StoryboardProject::factory()->create();

        Livewire::test(EditStoryboardProject::class, ['record' => $project->getRouteKey()])->assertOk();
    }

    public function test_edit_page_exposes_saved_reference_thumbnails_by_tag(): void
    {
        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');

        $project = StoryboardProject::factory()->create();
        $project->assets()->create(['tag' => 'hero', 'type' => 'character', 'file_path' => 'storyboard/inputs/hero.png']);
        $project->assets()->create(['tag' => 'no_image', 'type' => 'prop', 'file_path' => null]);

        Livewire::test(EditStoryboardProject::class, ['record' => $project->getRouteKey()])
            ->call('getStoryboardAssetUrls')
            ->assertReturned(fn ($map): bool => is_array($map)
                && array_key_exists('hero', $map)
                && ! array_key_exists('no_image', $map));
    }

    public function test_generate_frame_dispatches_a_job_and_marks_the_frame_generating(): void
    {
        Bus::fake([GenerateStoryboardFrameJob::class]);
        $project = StoryboardProject::factory()->create();
        $frame = StoryboardFrame::factory()->create(['project_id' => $project->id]);

        Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])
            ->call('generateFrame', $frame->id);

        $this->assertSame(StoryboardFrame::STATUS_GENERATING, $frame->refresh()->status);
        Bus::assertDispatched(GenerateStoryboardFrameJob::class);
    }

    public function test_the_builder_cannot_touch_another_projects_frame(): void
    {
        Bus::fake([GenerateStoryboardFrameJob::class]);
        $project = StoryboardProject::factory()->create();
        $foreign = StoryboardFrame::factory()->create(); // belongs to a different project

        Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])
            ->call('generateFrame', $foreign->id)
            ->call('approveFrame', $foreign->id);

        $this->assertSame(StoryboardFrame::STATUS_PENDING, $foreign->refresh()->status);
        $this->assertFalse($foreign->refresh()->is_approved);
        Bus::assertNotDispatched(GenerateStoryboardFrameJob::class);
    }

    public function test_improve_prompt_rewrites_the_frame_prompt_via_the_llm(): void
    {
        config()->set('services.openrouter.key', 'sk-or-test');
        config()->set('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        config()->set('services.openrouter.timeout', 30);
        $this->seed(StoryboardPipelineSeeder::class);

        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode([
                'improved_prompt' => 'A valiant knight with WHITE HAIR on horseback, @location',
            ])]]],
            'model' => 'google/gemini-2.5-flash',
            'usage' => ['cost' => 0.001],
        ], 200)]);

        $project = StoryboardProject::factory()->create();
        $frame = StoryboardFrame::factory()->create([
            'project_id' => $project->id,
            'image_prompt' => 'A valiant knight on horseback, @location',
        ]);

        Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])
            ->call('startImprove', $frame->id)
            ->set('improveInstruction', 'give the knight white hair')
            ->call('applyImprove', false);

        $this->assertSame('A valiant knight with WHITE HAIR on horseback, @location', $frame->refresh()->image_prompt);
        $this->assertNull($frame->refresh()->image_path); // no regenerate requested
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_approve_and_lock_toggle(): void
    {
        $project = StoryboardProject::factory()->create();
        $frame = StoryboardFrame::factory()->create(['project_id' => $project->id]);

        Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])
            ->call('approveFrame', $frame->id)
            ->call('toggleLock', $frame->id);

        $frame->refresh();
        $this->assertTrue($frame->is_approved);
        $this->assertTrue($frame->is_locked);
    }
}
