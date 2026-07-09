<?php

namespace Tests\Feature\Storyboard;

use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\CreateStoryboardProject;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\ListStoryboardProjects;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\StoryboardBuilder;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_creating_a_project_saves_inline_tagged_references(): void
    {
        Livewire::test(CreateStoryboardProject::class)
            ->fillForm([
                'title' => 'Pool Party',
                'story_idea' => 'A trailer featuring @hero at @location_pool',
                'duration_seconds' => 9,
                'frame_interval_seconds' => 3,
                'aspect_ratio' => '16:9',
                'assets' => [
                    ['tag' => 'hero', 'type' => 'character'],
                    ['tag' => 'location_pool', 'type' => 'location'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = StoryboardProject::query()->firstOrFail();
        $this->assertSame(2, $project->assets()->count());
        $this->assertEqualsCanonicalizing(['hero', 'location_pool'], $project->assets()->pluck('tag')->all());
        $this->assertSame(auth()->id(), $project->created_by);
    }

    public function test_the_builder_page_renders(): void
    {
        $project = StoryboardProject::factory()->create();
        StoryboardFrame::factory()->create(['project_id' => $project->id]);

        Livewire::test(StoryboardBuilder::class, ['record' => $project->getRouteKey()])->assertOk();
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
