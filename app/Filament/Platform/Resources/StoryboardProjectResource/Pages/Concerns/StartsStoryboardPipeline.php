<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns;

use App\Domain\Storyboard\StoryboardStep;
use App\Jobs\RunStoryboardPipelineJob;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;

/**
 * Kick off the storyboard pipeline for a project: pre-create the step rows as PENDING (so the
 * Builder shows progress IMMEDIATELY, before a worker picks the job up), flip the project to
 * RUNNING, and dispatch the run. Shared by the Builder's Run action and the form's Generate action.
 */
trait StartsStoryboardPipeline
{
    protected function startStoryboardPipeline(StoryboardProject $project): void
    {
        foreach (StoryboardStep::TEXT_STEPS as $stepKey) {
            $project->stepRuns()->updateOrCreate(
                ['step_key' => $stepKey],
                ['status' => StoryboardStepRun::STATUS_PENDING, 'error' => null, 'output' => null, 'duration_ms' => null],
            );
        }

        $project->update(['status' => StoryboardProject::STATUS_RUNNING]);
        RunStoryboardPipelineJob::dispatch($project->id);
    }
}
