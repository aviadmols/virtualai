<?php

namespace App\Jobs;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\FalEndpointSchema;
use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Domain\Storyboard\StoryboardVideoDirector;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    private const MAX_REFERENCE_IMAGES = 9; // fal's largest reference cap (happy-horse); lower-cap models are trimmed by the endpoint schema

    // Where the submitted reference prompt came from — surfaced in final_video_meta.
    private const PROMPT_MANUAL = 'manual';
    private const PROMPT_DIRECTOR = 'director';
    private const PROMPT_AUTO = 'auto';
    private const PROMPT_PREVIEW_CHARS = 500;

    // The FALLBACK directive when the admin leaves the prompt empty AND the director pass fails —
    // the video must FOLLOW the storyboard (same characters, plot, structure, timing). The story,
    // visual bible, character bible and the timed shot list are appended as DATA by autoPrompt().
    private const AUTO_DIRECTIVE = 'Create ONE continuous cinematic film that follows this storyboard EXACTLY, shot by shot, in order, hitting each shot\'s stated time window. '
        .'The reference images ARE the storyboard frames in shot order: match their characters (faces, hair, wardrobe), locations, lighting, palette and art style precisely — do not restyle or reinterpret them. '
        .'Maintain spatial continuity and consistent screen direction across cuts — characters keep their positions and facing between consecutive shots unless a shot shows them move; every shot change is an explicit, motivated camera transition (cut on action, match cut, push-in), never an unexplained jump. '
        .'Keep all motion natural and physically plausible; no morphing, no flicker, no new characters, no on-screen text. '
        .'Every quoted line of dialogue must be SPOKEN aloud by its character within its own shot\'s time window, clearly and lip-synced.';

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

        // Kling native i2v takes ONE input frame and clamps to 10s — it would silently animate
        // frame 1, drop the other references, and mistime the director's shot list. Fail loud
        // with the way forward instead (the Animate mode is the per-shot Kling path).
        if ($config->provider === ImageGenerationProvider::PROVIDER_KLING) {
            $this->markFailed($project, 'The clip model runs on Kling, which cannot render a multi-reference film in one call — use the "Animate every frame" mode, or switch the clip step to a Seedance-class model.');

            return;
        }

        $client = $router->for($config->provider);
        $ratio = ($this->ratio !== null && $this->ratio !== '') ? $this->ratio : ($project->aspect_ratio ?? self::DEFAULT_RATIO);
        // The seconds the model will ACTUALLY render (fal models clamp to a per-model enum) — the
        // prompt's shot timings are built against this so its clock matches the output.
        $effectiveSeconds = $this->effectiveSeconds($config);

        // Prompt precedence: the admin's manual prompt verbatim → the DIRECTOR pass (a multimodal
        // model composes a timed shot list from the frame images + storyboard) → the deterministic
        // auto prompt (the director must never block a video).
        $source = self::PROMPT_MANUAL;
        $prompt = ($this->prompt !== null && $this->prompt !== '') ? $this->prompt : null;

        if ($prompt === null) {
            $prompt = app(StoryboardVideoDirector::class)->compose($project, $refUrls, $effectiveSeconds, $this->resolution, $ratio);
            $source = $prompt !== null ? self::PROMPT_DIRECTOR : self::PROMPT_AUTO;
            $prompt ??= $this->autoPrompt($project, $effectiveSeconds);
        }

        $taskId = $client->submitTask($config->model, $prompt, $refUrls, [
            'resolution' => $this->resolution,
            'duration_seconds' => $this->seconds,
            'ratio' => $ratio,
        ]);

        $project->update([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
            'final_video_meta' => [
                'mode' => self::MODE_REFERENCE,
                'provider' => $config->provider,
                'model' => $config->model,
                'prediction_id' => $taskId,
                'resolution' => $this->resolution,
                'requested_seconds' => $this->seconds,
                'effective_seconds' => $effectiveSeconds,
                'prompt_source' => $source,
                'prompt_preview' => Str::limit($prompt, self::PROMPT_PREVIEW_CHARS),
                // Live progress for the builder card: the admin SEES it was sent + where it stands.
                'submitted_at' => now()->toIso8601String(),
                'last_status' => 'submitted',
                'polls' => 0,
            ],
        ]);

        $this->reschedule();
    }

    /**
     * The duration the resolved video model will actually render: fal models clamp the request to
     * a per-model enum (read from the endpoint's own schema); other providers take it as-is.
     */
    private function effectiveSeconds(OperationConfig $config): int
    {
        if ($config->provider !== ImageGenerationProvider::PROVIDER_FAL) {
            return $this->seconds;
        }

        $schema = app(FalEndpointSchema::class);

        return $schema->effectiveDuration($schema->inputSchema($config->model), $this->seconds) ?? $this->seconds;
    }

    /** Poll the in-flight reference-to-video prediction; on success download + store the final video. */
    private function pollReference(StoryboardProject $project, array $meta, VideoProviderRouter $router): void
    {
        $client = $router->for((string) ($meta['provider'] ?? ImageGenerationProvider::PROVIDER_ATLASCLOUD));

        try {
            $task = $client->pollTask((string) $meta['prediction_id']);
        } catch (Throwable $e) {
            // Surface the live state on the builder card even while retrying.
            $project->update(['final_video_meta' => array_merge($meta, [
                'last_status' => 'poll_error: '.$e->getMessage(),
                'polls' => $this->attempt,
            ])]);
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

        // Still rendering — record the provider's live status for the builder card.
        $project->update(['final_video_meta' => array_merge($meta, [
            'last_status' => (string) ($task['status'] ?? 'unknown'),
            'polls' => $this->attempt,
        ])]);

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
     * The deterministic FALLBACK video prompt (the director pass failed or is unseeded): the
     * directive to FOLLOW the storyboard + the story idea + the pipeline's visual bible and
     * character bible (the continuity contract) + the NUMBERED, TIMED shot list (timings rescaled
     * to the seconds the model will actually render). The variable parts are DATA, not creative
     * instructions.
     */
    private function autoPrompt(StoryboardProject $project, int $totalSeconds): string
    {
        $parts = [self::AUTO_DIRECTIVE];
        $parts[] = 'Total duration: '.$totalSeconds.'s — follow the shot timings below exactly.';

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

        if (($characters = StoryboardVideoDirector::characterLine($pipeline)) !== '') {
            $parts[] = 'Characters (keep identical throughout): '.$characters;
        }

        // The VISION ground truth of the tagged uploads — the strongest character-fidelity signal
        // (it describes the ACTUAL reference images the video must match).
        if (($analyses = StoryboardVideoDirector::referenceAnalysesLine($project)) !== '') {
            $parts[] = 'Reference image analyses (ground truth): '.$analyses;
        }

        // Numbered, timed shot lines (description, camera, action, motion, dialogue) — the same
        // formatter the director sees, so both paths speak the identical shot vocabulary.
        if (($shots = StoryboardVideoDirector::shotLines($project, $totalSeconds)) !== []) {
            $parts[] = "Shots in order:\n".implode("\n", $shots);
        }

        return implode("\n", $parts);
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
