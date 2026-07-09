<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ProductScanCaller;
use App\Domain\Credits\CreditMath;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use Throwable;

/**
 * StoryboardPipeline — runs the text pre-production steps in order and materialises the frames.
 *
 * Each step resolves its DB-managed AiOperation (model/prompt/params/schema) and calls the shared
 * strict-JSON caller (the same structured-output path the product scan uses). Single-object step
 * outputs land under project.pipeline[...]; the scene-breakdown step becomes storyboard_frames.
 * Every step is logged in storyboard_step_runs (progress + cost). NOT a money path — cost is
 * recorded for display only, never charged. A failed step fails the project (no partial frames).
 */
final class StoryboardPipeline
{
    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly ProductScanCaller $caller,
    ) {}

    public function run(StoryboardProject $project): void
    {
        $project->update(['status' => StoryboardProject::STATUS_RUNNING]);
        $project->stepRuns()->delete(); // a re-run starts a clean log

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
        $config = $this->resolver->for($stepKey);
        $vars = $this->vars($project);

        $run = $project->stepRuns()->create([
            'step_key' => $stepKey,
            'status' => StoryboardStepRun::STATUS_RUNNING,
            'input' => $vars,
            'provider' => $config->provider,
            'model' => $config->model,
        ]);

        $startedAt = hrtime(true);

        try {
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

        $this->persist($project, $stepKey, $result->json);

        return true;
    }

    /** @param array<string,mixed> $json */
    private function persist(StoryboardProject $project, string $stepKey, array $json): void
    {
        if ($stepKey === AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN) {
            $this->materialiseFrames($project, is_array($json['frames'] ?? null) ? $json['frames'] : []);

            return;
        }

        $pipeline = $project->pipeline ?? [];
        $pipeline[StoryboardStep::PIPELINE_KEY[$stepKey]] = $json;
        $project->update(['pipeline' => $pipeline]);
    }

    /** @param array<int,array<string,mixed>> $frames */
    private function materialiseFrames(StoryboardProject $project, array $frames): void
    {
        $project->frames()->delete(); // replace on a fresh run

        foreach ($frames as $f) {
            $project->frames()->create([
                'frame_number' => (int) ($f['frame_number'] ?? 0),
                'start_second' => (int) ($f['start_second'] ?? 0),
                'end_second' => (int) ($f['end_second'] ?? 0),
                'description' => $f['description'] ?? null,
                'camera_angle' => $f['camera_angle'] ?? null,
                'composition' => $f['composition'] ?? null,
                'action' => $f['action'] ?? null,
                'characters' => is_array($f['characters'] ?? null) ? $f['characters'] : [],
                'reference_tags' => is_array($f['reference_tags'] ?? null) ? $f['reference_tags'] : [],
                'text_overlay' => $f['text_overlay'] ?? null,
                'image_prompt' => $f['image_prompt'] ?? null,
                'negative_prompt' => $f['negative_prompt'] ?? null,
                'status' => StoryboardFrame::STATUS_PENDING,
            ]);
        }
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
        $tags = $project->assets()->pluck('tag')->map(static fn (string $t): string => '@'.$t)->implode(', ');

        return [
            'story_idea' => (string) $project->story_idea,
            'genre' => (string) $project->genre,
            'duration' => (string) $project->duration_seconds,
            'frame_interval' => (string) $project->frame_interval_seconds,
            'frame_count' => (string) $project->expectedFrameCount(),
            'aspect_ratio' => (string) $project->aspect_ratio,
            'reference_tags' => $tags,
            'clean_story' => $this->encode($pipeline[StoryboardProject::PIPE_STORY] ?? null),
            'genre_profile' => $this->encode($pipeline[StoryboardProject::PIPE_GENRE] ?? null),
            'characters' => $this->encode($pipeline[StoryboardProject::PIPE_CHARACTERS] ?? null),
            'visual_bible' => $this->encode($pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] ?? null),
        ];
    }

    private function encode(?array $value): string
    {
        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function costMicro(\App\Domain\Ai\ParsedCost $cost): ?int
    {
        return $cost->available && $cost->costUsd !== null ? CreditMath::usdToMicro($cost->costUsd) : null;
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
