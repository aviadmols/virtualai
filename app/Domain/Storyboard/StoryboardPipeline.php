<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ParsedCost;
use App\Domain\Ai\StoryboardTextCaller;
use App\Domain\Credits\CreditMath;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use Throwable;

/**
 * StoryboardPipeline — runs the TWO planning calls in order and materialises the frames.
 *
 * Call 1 (Story Director) locks the whole plan in one structured output — story bible, genre
 * profile, character/asset bible, visual bible and the shot timing; its sections are split into
 * the project's pipeline bags. Call 2 (Scene Breakdown) receives the locked plan and returns the
 * per-frame SCENE beats only; frame timing is stamped from the LOCKED plan (never the model's own
 * arithmetic) and the final image_prompt is assembled deterministically by the composer, so the
 * character/style text is IDENTICAL in every frame. Every step is logged in storyboard_step_runs
 * (progress + cost). NOT a money path — cost is recorded for display only, never charged. A failed
 * step fails the project (no partial frames).
 */
final class StoryboardPipeline
{
    // === CONSTANTS ===
    // The step-run reason when the scene breakdown returned no usable frames.
    private const ERROR_NO_FRAMES = 'The scene breakdown returned no frames.';

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly StoryboardTextCaller $caller,
        private readonly StoryboardAssetAnalyzer $assetAnalyzer,
        private readonly StoryboardPromptComposer $composer,
    ) {}

    public function run(StoryboardProject $project): void
    {
        $project->update(['status' => StoryboardProject::STATUS_RUNNING]);

        // Ground-truth first: any reference upload still missing its VISION description is
        // analyzed now, so the planning prompts always see what the tagged images really contain.
        $this->assetAnalyzer->analyzeMissing($project);

        foreach (StoryboardStep::TEXT_STEPS as $stepKey) {
            if (! $this->runStep($project, $stepKey)) {
                $project->update(['status' => StoryboardProject::STATUS_FAILED]);

                return;
            }

            $project->refresh(); // pick up the pipeline output the prior step wrote
        }

        $project->update(['status' => StoryboardProject::STATUS_READY]);
    }

    private function runStep(StoryboardProject $project, string $stepKey): bool
    {
        // Create/reset the row FIRST (before resolving) so ANY failure — incl. an unseeded
        // operation — is visible with its reason instead of an invisible crash. updateOrCreate
        // keeps one row per step across re-runs.
        $run = $project->stepRuns()->updateOrCreate(
            ['step_key' => $stepKey],
            ['status' => StoryboardStepRun::STATUS_RUNNING, 'error' => null, 'output' => null, 'duration_ms' => null],
        );

        $startedAt = hrtime(true);

        try {
            $config = $this->resolver->for($stepKey);
            $vars = $this->vars($project);
            $run->update(['input' => $vars, 'provider' => $config->provider, 'model' => $config->model]);
            $result = $this->caller->extract($config, $vars);
        } catch (Throwable $e) {
            $run->update([
                'status' => StoryboardStepRun::STATUS_FAILED,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            return false;
        }

        $run->update([
            'status' => StoryboardStepRun::STATUS_SUCCEEDED,
            'output' => $result->json,
            'model' => $result->modelUsed,
            'cost_micro_usd' => $this->costMicro($result->cost),
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);

        if (! $this->persist($project, $stepKey, $result->json)) {
            $run->update(['status' => StoryboardStepRun::STATUS_FAILED, 'error' => self::ERROR_NO_FRAMES]);

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $json @return bool false when the step's output was unusable */
    private function persist(StoryboardProject $project, string $stepKey, array $json): bool
    {
        if ($stepKey === AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN) {
            return $this->materialiseFrames($project, is_array($json['frames'] ?? null) ? $json['frames'] : []);
        }

        // Story Director: split the single output into the pipeline bags every downstream
        // reader already knows, and LOCK the normalized shot timing alongside them.
        $pipeline = $project->pipeline ?? [];

        foreach (StoryboardStep::DIRECTOR_SECTIONS as $section => $bag) {
            if (is_array($json[$section] ?? null)) {
                $pipeline[$bag] = $json[$section];
            }
        }

        $pipeline[StoryboardProject::PIPE_TIMING] = StoryboardTimingPlan::normalize(
            $json['shot_timing'] ?? null,
            (int) $project->duration_seconds,
            $project->minShotCount(),
            $project->maxShotCount(),
            (int) $project->frame_interval_seconds,
            $project->maxShotSeconds(),
        );

        $project->update(['pipeline' => $pipeline]);

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $frames
     * @return bool false when the breakdown returned nothing usable (the caller fails the run)
     */
    private function materialiseFrames(StoryboardProject $project, array $frames): bool
    {
        // A breakdown with no frames would DELETE the storyboard and leave an empty film
        // reported as ready — treat it as a failed step instead.
        if ($frames === []) {
            return false;
        }

        $project->frames()->delete(); // replace on a fresh run

        $pipeline = $project->pipeline ?? [];
        $charactersBag = is_array($pipeline[StoryboardProject::PIPE_CHARACTERS] ?? null) ? $pipeline[StoryboardProject::PIPE_CHARACTERS] : [];
        $visualBible = is_array($pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] ?? null) ? $pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] : [];

        // The LOCKED plan is the only authority for BOTH count and timing — the breakdown's
        // own numbers are ignored. Lenient re-read: stored plans of any vintage re-normalize.
        $plan = StoryboardTimingPlan::fromStored(
            $pipeline[StoryboardProject::PIPE_TIMING] ?? null,
            (int) $project->duration_seconds,
            (int) $project->frame_interval_seconds,
        );

        usort($frames, static fn (array $a, array $b): int => (int) ($a['frame_number'] ?? 0) <=> (int) ($b['frame_number'] ?? 0));
        $frames = array_values($frames);

        // Reconcile a count mismatch: extra breakdown frames are dropped; when the breakdown
        // returned fewer, the LAST created frame stretches to the plan's end so the film still
        // covers the full duration.
        $count = min(count($frames), count($plan));

        for ($i = 0; $i < $count; $i++) {
            $f = $frames[$i];
            $slot = $plan[$i];
            $isLast = $i === $count - 1;

            $project->frames()->create([
                'frame_number' => $i + 1,
                'start_second' => $slot['start_second'],
                'end_second' => $isLast ? $plan[count($plan) - 1]['end_second'] : $slot['end_second'],
                'description' => $f['description'] ?? null,
                'camera_angle' => $f['camera_angle'] ?? null,
                'composition' => $f['composition'] ?? null,
                'action' => $f['action'] ?? null,
                'characters' => is_array($f['characters'] ?? null) ? $f['characters'] : [],
                'reference_tags' => is_array($f['reference_tags'] ?? null) ? $f['reference_tags'] : [],
                'text_overlay' => $f['text_overlay'] ?? null,
                // The breakdown's motion beat, else the slot's locked camera move — the clip
                // step always has SOMETHING concrete to animate.
                'motion_prompt' => is_string($f['motion'] ?? null) && trim($f['motion']) !== ''
                    ? $f['motion']
                    : ($slot['camera_movement'] ?? null),
                // Deterministic assembly: scene beat + locked character blocks + locked style.
                'image_prompt' => $this->composer->compose($f, $charactersBag, $visualBible),
                'negative_prompt' => $this->composer->negativePrompt($f['negative_prompt'] ?? null, $visualBible),
                'status' => StoryboardFrame::STATUS_PENDING,
                'meta' => ['scene_prompt' => $f['scene_prompt'] ?? $f['image_prompt'] ?? null],
            ]);
        }

        return true;
    }

    /**
     * The prompt placeholders for a step: the brief + the accumulated prior outputs (JSON-encoded
     * so strtr substitution stays a literal swap — never Blade).
     *
     * @return array<string,string>
     */
    private function vars(StoryboardProject $project): array
    {
        $pipeline = $project->pipeline ?? [];
        $assets = $project->assets()->orderBy('id')->get();
        $tags = $assets->pluck('tag')->map(static fn (string $t): string => '@'.$t)->implode(', ');

        // The VISION ground truth per @tag ("@image1 (character): …") — what the uploaded image
        // REALLY contains, so planned characters match the references.
        $descriptions = $assets->map(static function ($asset): string {
            $description = trim((string) $asset->description);

            return '@'.$asset->tag.' ('.$asset->type.'): '.($description !== '' ? $description : 'no visual analysis available');
        })->implode("\n");

        return [
            'story_idea' => (string) $project->story_idea,
            'reference_descriptions' => $descriptions,
            'genre' => (string) $project->genre,
            'duration' => (string) $project->duration_seconds,
            'frame_interval' => (string) $project->frame_interval_seconds,
            // The locked plan's count once the director ran (the breakdown consumes this);
            // before that, the pacing estimate. Plus the director's shot-freedom bounds.
            'frame_count' => (string) $project->plannedShotCount(),
            'min_shots' => (string) $project->minShotCount(),
            'max_shots' => (string) $project->maxShotCount(),
            'max_shot_seconds' => (string) $project->maxShotSeconds(),
            'aspect_ratio' => (string) $project->aspect_ratio,
            'reference_tags' => $tags,
            'clean_story' => $this->encode($pipeline[StoryboardProject::PIPE_STORY] ?? null),
            'genre_profile' => $this->encode($pipeline[StoryboardProject::PIPE_GENRE] ?? null),
            'characters' => $this->encode($pipeline[StoryboardProject::PIPE_CHARACTERS] ?? null),
            'visual_bible' => $this->encode($pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] ?? null),
            // LOCKED planning decisions the scene breakdown must obey (read-only data).
            'shot_timing' => $this->encode($pipeline[StoryboardProject::PIPE_TIMING] ?? null),
            'content_type' => (string) ($pipeline[StoryboardProject::PIPE_STORY]['content_type'] ?? StoryboardProject::CONTENT_COMPLETE),
        ];
    }

    private function encode(?array $value): string
    {
        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function costMicro(ParsedCost $cost): ?int
    {
        return $cost->available && $cost->costUsd !== null ? CreditMath::usdToMicro($cost->costUsd) : null;
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
