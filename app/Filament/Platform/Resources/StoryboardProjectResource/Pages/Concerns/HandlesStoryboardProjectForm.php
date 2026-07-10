<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns;

use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\StoryboardBuilder;
use App\Jobs\AnalyzeStoryboardAssetJob;
use App\Models\StoryboardAsset;
use App\Models\StoryboardProject;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Support\Facades\DB;

/**
 * Shared create/edit form lifecycle for a storyboard project:
 *
 * 1. The reference-image pool is a single `->multiple()` FileUpload (virtual field `reference_uploads`).
 *    On save it is reconciled into AUTO-NUMBERED StoryboardAsset rows — @image1, @image2 … in upload
 *    order (the number IS the tag), so the admin never types a name. Delete-and-recreate is safe:
 *    nothing external references asset ids; the whole pipeline binds by the `tag` string.
 * 2. A "Generate" footer dropdown that persists the form and (optionally) starts the pipeline, then
 *    lands on the Builder — like the reference playground's Generate button.
 */
trait HandlesStoryboardProjectForm
{
    use StartsStoryboardPipeline;

    // === CONSTANTS ===
    private const UPLOAD_FIELD = 'reference_uploads';
    private const TAG_PREFIX = 'image';
    private const DEFAULT_TYPE = StoryboardAsset::TYPE_CHARACTER;
    private const DEFAULT_STRENGTH = 70;

    /** Ordered upload paths pulled out of the virtual field, reconciled after the record is saved. */
    protected array $numberedReferencePaths = [];

    protected bool $hasNumberedReferences = false;

    /** Whether the current submit came from the "Generate storyboard" action (run the pipeline). */
    public bool $runPipelineAfterSave = false;

    /** Each page wires its own persist call (create() vs save()), since the flows differ. */
    abstract protected function persistStoryboardForm(): void;

    /** Strip the virtual upload field from $data and stash the ordered paths for the reconcile. */
    protected function stashReferenceUploads(array $data): array
    {
        if (array_key_exists(self::UPLOAD_FIELD, $data)) {
            $this->numberedReferencePaths = array_values(array_filter((array) $data[self::UPLOAD_FIELD]));
            $this->hasNumberedReferences = true;
            unset($data[self::UPLOAD_FIELD]);
        }

        return $data;
    }

    /** Hydrate the virtual upload field from the saved (ordered) asset paths for the Edit form. */
    protected function hydrateReferenceUploads(array $data, StoryboardProject $project): array
    {
        $data[self::UPLOAD_FIELD] = $project->assets()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->pluck('file_path')
            ->all();

        return $data;
    }

    /** Reconcile the stashed paths into auto-numbered @image1..@imageN rows, then optionally run. */
    protected function afterStoryboardPersisted(): void
    {
        if ($this->hasNumberedReferences) {
            $analyze = [];

            DB::transaction(function () use (&$analyze): void {
                // Keep each file's VISION analysis (description + detected type) across the
                // delete-and-recreate renumbering — an unchanged image is never re-analyzed.
                $prior = $this->record->assets()->whereNotNull('file_path')->get()->keyBy('file_path');
                $this->record->assets()->delete();

                foreach ($this->numberedReferencePaths as $i => $path) {
                    $existing = $prior->get($path);

                    $asset = $this->record->assets()->create([
                        'tag' => self::TAG_PREFIX.($i + 1),
                        'type' => $existing?->type ?? self::DEFAULT_TYPE,
                        'file_path' => $path,
                        'description' => $existing?->description,
                        'reference_strength' => self::DEFAULT_STRENGTH,
                    ]);

                    if (blank($asset->description)) {
                        $analyze[] = $asset->id;
                    }
                }
            });

            // Pre-warm the vision analysis for NEW images (the pipeline still covers stragglers).
            foreach ($analyze as $assetId) {
                AnalyzeStoryboardAssetJob::dispatch($assetId);
            }
        }

        if ($this->runPipelineAfterSave) {
            $this->startStoryboardPipeline($this->record);
        }
    }

    /** The "Generate" footer dropdown (persist + optionally run the pipeline → Builder). */
    protected function generateActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('generateStoryboard')
                ->label(__('platform.storyboard.generate_now'))
                ->icon('heroicon-o-sparkles')
                ->action(fn () => $this->submitAndGenerate(true)),
            Action::make('openBuilder')
                ->label(__('platform.storyboard.open_builder'))
                ->icon('heroicon-o-squares-2x2')
                ->action(fn () => $this->submitAndGenerate(false)),
        ])
            ->label(__('platform.storyboard.generate'))
            ->icon('heroicon-o-sparkles')
            ->button();
    }

    public function submitAndGenerate(bool $run): void
    {
        $this->runPipelineAfterSave = $run;
        $this->persistStoryboardForm();
    }

    /** Land on the Builder for the saved record (used by the Edit flow after a no-redirect save). */
    protected function redirectToBuilder(): void
    {
        $this->redirect(StoryboardBuilder::getUrl(['record' => $this->record]));
    }
}
