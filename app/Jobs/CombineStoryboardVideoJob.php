<?php

namespace App\Jobs;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CombineStoryboardVideoJob — build ONE MP4 from a project. Three modes:
 *
 * - REFERENCE: generate ONE coherent AI video from a PROMPT + the project's reference images
 *   (AtlasCloud reference-to-video). The admin controls the prompt, duration, structure (ratio) and
 *   resolution. Async: submit → re-dispatch to poll → download + store.
 * - ANIMATE: ensure every frame has a real AI motion clip (submit the missing ones via
 *   GenerateStoryboardClipJob), poll until they finish, then stitch the CLIPS into one film (ffmpeg).
 * - SLIDESHOW: a one-shot ffmpeg slideshow of the still frame images (fast, free preview).
 *
 * Runs on the media queue. Not tenant-aware, never charges (video is billed at generation, not here).
 * Any error is surfaced in final_video_meta so the builder shows it. tries=1.
 */
final class CombineStoryboardVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const MODE_REFERENCE = 'reference';
    public const MODE_ANIMATE = 'animate';
    public const MODE_SLIDESHOW = 'slideshow';

    public int $tries = 1;
    public int $timeout = 110;

    private const MAX_POLL_ATTEMPTS = 60;   // 60 × 15s ≈ 15 min for the render(s) to finish
    private const POLL_DELAY = 15;
    private const DEFAULT_RATIO = 'adaptive';
    private const MAX_REFERENCE_IMAGES = 8; // cap the frames/refs sent inline to the provider

    // The default directive when the admin leaves the prompt empty — the video must FOLLOW the
    // storyboard (same characters, plot, structure). The story, visual bible, character bible and
    // per-frame scenes are appended as DATA by autoPrompt(), not as creative instructions.
    private const AUTO_DIRECTIVE = 'Create ONE continuous cinematic film that follows this storyboard EXACTLY, scene by scene, in order. '
        .'The reference images ARE the storyboard frames: match their characters (faces, hair, wardrobe), locations, lighting, palette and art style precisely — do not restyle or reinterpret them. '
        .'Preserve the plot and the film\'s structure across the whole video, with smooth, motivated transitions between scenes; keep all motion natural and physically plausible; no morphing, no flicker, no new characters, no on-screen text.';

    // Cap the character-continuity lines appended to the auto prompt.
    private const MAX_PROMPT_CHARACTERS = 5;

    public function __construct(
        public readonly int $projectId,
        public readonly string $mode,
        public readonly string $resolution,
        public readonly int $seconds,
        public readonly ?string $prompt = null,
        public readonly ?string $ratio = null,
        public readonly int $attempt = 0,
    ) {
        $this->onQueue((string) config('trayon.queues.media'));
    }

    public function handle(StoryboardVideoComposer $composer): void
    {
        $project = StoryboardProject::find($this->projectId);

        if ($project === null) {
            return;
        }

        try {
            match ($this->mode) {
                self::MODE_REFERENCE => $this->coordinateReference($project),
                self::MODE_SLIDESHOW => $this->finish($project, $composer->compose($project, $this->seconds, $this->resolution)),
                default => $this->coordinateAnimate($project, $composer),
            };
        } catch (Throwable $e) {
            $this->markFailed($project, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate ONE video from the story prompt + the project's reference images (reference-to-video).
     * First firing submits + stores the prediction id; later firings poll → download → store.
     */
    private function coordinateReference(StoryboardProject $project): void
    {
        $meta = is_array($project->final_video_meta) ? $project->final_video_meta : [];
        $router = app(VideoProviderRouter::class);

        if (! empty($meta['prediction_id'])) {
            $this->pollReference($project, $meta, $router);

            return;
        }

        $refUrls = $this->referenceImageUrls($project);
        if ($refUrls === []) {
            $this->markFailed($project, 'No reference images to generate from — add reference images to the project first.');

            return;
        }

        $config = app(AiOperationResolver::class)->for(AiOperation::KEY_STORYBOARD_CLIP);
        $client = $router->for($config->provider);
        // No prompt entered → auto-build one from the storyboard (keeps characters, plot, structure).
        $prompt = ($this->prompt !== null && $this->prompt !== '') ? $this->prompt : $this->autoPrompt($project);

        $taskId = $client->submitTask($config->model, $prompt, $refUrls, [
            'resolution' => $this->resolution,
            'duration_seconds' => $this->seconds,
            'ratio' => ($this->ratio !== null && $this->ratio !== '') ? $this->ratio : ($project->aspect_ratio ?? self::DEFAULT_RATIO),
        ]);

        $project->update([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
            'final_video_meta' => [
                'mode' => self::MODE_REFERENCE,
                'provider' => $config->provider,
                'model' => $config->model,
                'prediction_id' => $taskId,
                'resolution' => $this->resolution,
            ],
        ]);

        $this->reschedule();
    }

    /** Poll the in-flight reference-to-video prediction; on success download + store the final video. */
    private function pollReference(StoryboardProject $project, array $meta, VideoProviderRouter $router): void
    {
        $client = $router->for((string) ($meta['provider'] ?? ImageGenerationProvider::PROVIDER_ATLASCLOUD));

        try {
            $task = $client->pollTask((string) $meta['prediction_id']);
        } catch (Throwable $e) {
            $this->rescheduleOrFail($project, 'Video generation poll failed: '.$e->getMessage());

            return;
        }

        if ($client->succeeded($task)) {
            $url = data_get($task, 'content.video_url');
            $bytes = (is_string($url) && $url !== '') ? $client->downloadVideo($url) : null;

            if ($bytes === null) {
                $this->rescheduleOrFail($project, 'Generated video could not be downloaded.');

                return;
            }

            $stored = app(MediaStorage::class)->storeStoryboardVideo($project->id, $bytes);
            $project->update([
                'final_video_path' => $stored->path,
                'final_video_status' => StoryboardProject::VIDEO_READY,
                'final_video_meta' => array_merge($meta, ['status' => 'ready']),
            ]);

            return;
        }

        if (in_array((string) ($task['status'] ?? ''), VideoGenerationProvider::TERMINAL_FAILURE, true)) {
            $this->markFailed($project, (string) (data_get($task, 'error.message') ?: 'Video generation failed.'));

            return;
        }

        $this->rescheduleOrFail($project, 'Video generation timed out.');
    }

    /**
     * Submit a clip for every frame that still needs one, poll by re-dispatching until none are
     * rendering, then stitch the ready clips into one film.
     */
    private function coordinateAnimate(StoryboardProject $project, StoryboardVideoComposer $composer): void
    {
        if (! $project->frames()->whereNotNull('image_path')->exists()) {
            $this->markFailed($project, 'No generated frame images to animate.');

            return;
        }

        foreach ($project->frames()->whereNotNull('image_path')->get() as $frame) {
            $needsClip = $frame->video_path === null
                && $frame->video_status !== StoryboardFrame::VIDEO_GENERATING
                && $frame->video_status !== StoryboardFrame::VIDEO_FAILED;

            if ($needsClip) {
                $frame->update(['video_status' => StoryboardFrame::VIDEO_GENERATING, 'video_poll_attempts' => 0]);
                GenerateStoryboardClipJob::dispatch($frame->id);
            }
        }

        $rendering = $project->frames()
            ->whereNotNull('image_path')
            ->whereNull('video_path')
            ->where('video_status', StoryboardFrame::VIDEO_GENERATING)
            ->exists();

        if ($rendering && $this->attempt < self::MAX_POLL_ATTEMPTS) {
            $this->reschedule();

            return;
        }

        // No clip is still rendering (or we ran out of patience) — stitch whatever is ready.
        if ($project->frames()->whereNotNull('video_path')->count() === 0) {
            $reasons = $this->clipFailureReasons($project);

            // Detailed reason in the log AND on the builder card, so the real cause is visible
            // (usually: the video provider could not fetch the frame image, a bad/retired model id,
            // or a missing provider key). Switching the clip provider to AtlasCloud avoids the
            // image-reachability failure since it sends the frame inline as base64.
            Log::warning('storyboard.combine.all_clips_failed', [
                'project_id' => $project->id,
                'mode' => $this->mode,
                'reasons' => $reasons !== '' ? $reasons : 'no per-frame error recorded',
            ]);

            $this->markFailed($project, trim('All frame clips failed to render. '.$reasons));

            return;
        }

        $this->finish($project, $composer->concatClips($project, $this->resolution));
    }

    /**
     * Signed URLs to feed the reference-to-video: the generated storyboard FRAMES (they carry the
     * characters + scenes the video must follow), capped; falls back to the uploaded @image refs.
     */
    private function referenceImageUrls(StoryboardProject $project): array
    {
        $media = app(MediaStorage::class);

        $urls = $project->frames()
            ->whereNotNull('image_path')
            ->orderBy('frame_number')
            ->limit(self::MAX_REFERENCE_IMAGES)
            ->get()
            ->map(fn ($frame): ?string => $media->signedUrl($frame->image_path))
            ->filter()
            ->values()
            ->all();

        if ($urls !== []) {
            return $urls;
        }

        return $project->assets()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get()
            ->map(fn ($asset): ?string => $media->signedUrl($asset->file_path))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Auto-build the video prompt from the storyboard when the admin leaves it empty: a fixed
     * directive to FOLLOW the storyboard (same characters, plot, structure) + the story idea +
     * the pipeline's visual bible and character bible (the continuity contract) + every frame's
     * description, in order. The variable parts are DATA, not a creative prompt.
     */
    private function autoPrompt(StoryboardProject $project): string
    {
        $scenes = $project->frames()
            ->orderBy('frame_number')
            ->get()
            ->map(fn ($frame): string => trim((string) $frame->description))
            ->filter()
            ->values()
            ->all();

        $parts = [self::AUTO_DIRECTIVE];

        if (($story = trim((string) $project->story_idea)) !== '') {
            $parts[] = 'Story: '.$story;
        }

        $pipeline = is_array($project->pipeline) ? $project->pipeline : [];
        $bible = $pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] ?? null;
        $bible = is_array($bible) ? $bible : [];

        if (($style = trim((string) ($bible['global_style'] ?? ''))) !== '') {
            $parts[] = 'Visual style: '.$style;
        }

        if (($rules = trim((string) ($bible['continuity_rules'] ?? ''))) !== '') {
            $parts[] = 'Continuity rules: '.$rules;
        }

        if (($characters = $this->characterLine($pipeline)) !== '') {
            $parts[] = 'Characters (keep identical throughout): '.$characters;
        }

        if ($scenes !== []) {
            $parts[] = 'Scenes in order: '.implode(' | ', $scenes);
        }

        return implode("\n", $parts);
    }

    /** "Name — description; …" for the pipeline's characters, capped. Empty when none exist. */
    private function characterLine(array $pipeline): string
    {
        $characters = data_get($pipeline, StoryboardProject::PIPE_CHARACTERS.'.characters');

        if (! is_array($characters)) {
            return '';
        }

        return collect($characters)
            ->take(self::MAX_PROMPT_CHARACTERS)
            ->map(static function ($character): ?string {
                $name = is_array($character) ? trim((string) ($character['name'] ?? '')) : '';
                $description = is_array($character) ? trim((string) ($character['description'] ?? '')) : '';

                return $name !== '' ? trim($name.($description !== '' ? ' — '.$description : '')) : null;
            })
            ->filter()
            ->implode('; ');
    }

    /** Aggregate each frame's clip-failure reason (video_meta.error) for the log + on-screen error. */
    private function clipFailureReasons(StoryboardProject $project): string
    {
        return $project->frames()
            ->whereNotNull('image_path')
            ->orderBy('frame_number')
            ->get()
            ->map(function (StoryboardFrame $frame): ?string {
                $error = is_array($frame->video_meta) ? ($frame->video_meta['error'] ?? null) : null;

                return $error ? 'Frame #'.$frame->frame_number.': '.$error : null;
            })
            ->filter()
            ->implode(' | ');
    }

    private function finish(StoryboardProject $project, string $path): void
    {
        $project->update([
            'final_video_path' => $path,
            'final_video_status' => StoryboardProject::VIDEO_READY,
            'final_video_meta' => ['mode' => $this->mode, 'resolution' => $this->resolution],
        ]);
    }

    /** Re-dispatch this job to poll again (same params, next attempt). */
    private function reschedule(): void
    {
        self::dispatch($this->projectId, $this->mode, $this->resolution, $this->seconds, $this->prompt, $this->ratio, $this->attempt + 1)
            ->delay(now()->addSeconds(self::POLL_DELAY));
    }

    /** Reschedule while attempts remain, else fail with the given reason. */
    private function rescheduleOrFail(StoryboardProject $project, string $reason): void
    {
        if ($this->attempt < self::MAX_POLL_ATTEMPTS) {
            $this->reschedule();

            return;
        }

        $this->markFailed($project, $reason);
    }

    public function failed(Throwable $e): void
    {
        $project = StoryboardProject::find($this->projectId);

        if ($project !== null && $project->final_video_status === StoryboardProject::VIDEO_GENERATING) {
            $this->markFailed($project, $e->getMessage());
        }
    }

    private function markFailed(?StoryboardProject $project, string $error): void
    {
        $project?->update([
            'final_video_status' => StoryboardProject::VIDEO_FAILED,
            'final_video_meta' => ['error' => $error],
        ]);
    }
}
