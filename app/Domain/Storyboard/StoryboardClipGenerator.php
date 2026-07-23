<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\KlingVideoClient;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use Throwable;

/**
 * StoryboardClipGenerator — animates a frame's selected image into a short video clip.
 *
 * Uses the storyboard_clip AiOperation (admin-configured model + params + motion prompt) and submits
 * an image-to-video task via the provider the operation resolves to (Kling / BytePlus / AtlasCloud /
 * fal) — VideoProviderRouter picks the client (the frame image = the input reference).
 *
 * SHOT CONNECTION (Kling): the NEXT frame's image rides as the clip's END frame (image_tail),
 * so clip N lands exactly where clip N+1 begins and consecutive shots CONNECT. When a model/mode
 * rejects the tail (Kling 400s vary by line), the submit retries ONCE without it — a running clip
 * beats a perfect one that never renders. Async: this only SUBMITS + records the task id;
 * PollStoryboardClipJob completes it. NOT a money path.
 */
final class StoryboardClipGenerator
{
    // === CONSTANTS ===
    // The {{dialogue}} template value when the frame has a spoken line; empty otherwise, so a
    // silent frame's prompt carries no dialogue text at all.
    private const DIALOGUE_PREFIX = 'The character speaks this line aloud, clearly and lip-synced: ';

    // Per-clip duration bounds when the operation params carry none: the clip runs the FRAME's
    // locked shot length, clamped into what the clip models reliably render in one piece.
    private const PARAM_MIN_CLIP = 'min_clip_seconds';

    private const PARAM_MAX_CLIP = 'max_clip_seconds';

    private const DEFAULT_MIN_CLIP_SECONDS = 3;

    private const DEFAULT_MAX_CLIP_SECONDS = 12;

    // video_meta keys recording the tail fallback (visible in the builder's process log).
    private const META_TAIL_DROPPED = 'tail_dropped';

    private const META_TAIL_ERROR = 'tail_error';

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly VideoProviderRouter $router,
        private readonly MediaStorage $media,
    ) {}

    /** Submit the clip task for a frame. Returns true when a task was created. */
    public function submit(StoryboardFrame $frame): bool
    {
        if ($frame->image_path === null) {
            return false; // nothing to animate yet
        }

        $firstFrame = $this->media->signedUrl($frame->image_path);
        if ($firstFrame === null) {
            return false;
        }

        $config = $this->resolver->for(AiOperation::KEY_STORYBOARD_CLIP);
        $prompt = $config->substituteUser([
            'image_prompt' => (string) $frame->image_prompt,
            'motion' => (string) $frame->motion_prompt,
            // The locked shot's camera work (angle + composition) — concrete craft the video
            // model executes, on top of the motion beat.
            'camera' => trim(implode(' — ', array_filter([
                trim((string) $frame->camera_angle),
                trim((string) $frame->composition),
            ]))),
            'dialogue' => filled($frame->dialogue)
                ? self::DIALOGUE_PREFIX.'"'.trim((string) $frame->dialogue).'"'
                : '',
        ]);
        $baseUrl = $this->baseUrl($config->model);
        $video = $this->router->for($config->provider);

        // The clip runs the FRAME's locked shot length (shot-based derivation), clamped into
        // the admin-configured bounds — never one fixed duration for every shot.
        $params = array_merge($config->params, [
            'duration_seconds' => $this->clipSeconds($frame, $config->params),
        ]);

        // SHOT CONNECTION: on Kling, the NEXT shot's opening frame is this clip's END frame.
        $tail = $config->provider === ImageGenerationProvider::PROVIDER_KLING
            ? $this->nextFrameUrl($frame)
            : null;

        if ($tail !== null) {
            $params[KlingVideoClient::PARAM_IMAGE_TAIL] = $tail;
        }

        $frame->update([
            'video_status' => StoryboardFrame::VIDEO_GENERATING,
            'video_poll_attempts' => 0,
            // Video is flat-rate (no inline USD) — carry the per-clip price so the poller records it.
            // The provider is carried so the poller resolves the SAME upstream client that submitted.
            'video_meta' => ['provider' => $config->provider, 'base_url' => $baseUrl, 'model' => $config->model, 'cost' => $config->flatRatePriceMicroUsd()],
        ]);

        try {
            $taskId = $this->submitWithTailFallback($video, $config->model, $prompt, $firstFrame, $params, $baseUrl, $frame, $tail !== null);
        } catch (Throwable $e) {
            $frame->update([
                'video_status' => StoryboardFrame::VIDEO_FAILED,
                'video_meta' => array_merge($frame->video_meta ?? [], ['error' => $e->getMessage()]),
            ]);

            return false;
        }

        // The clip LANDS on the next shot's frame only when the tail was sent AND not dropped by a
        // 400 retry. The composer keeps a landed clip at full length so that connecting frame
        // survives the concat; any other clip is trimmed to its shot seconds.
        $landed = $tail !== null && ! ($frame->video_meta[self::META_TAIL_DROPPED] ?? false);

        $frame->update([
            'video_task_id' => $taskId,
            'video_meta' => array_merge($frame->video_meta ?? [], [StoryboardFrame::META_TAIL_APPLIED => $landed]),
        ]);

        return true;
    }

    /**
     * Submit, retrying ONCE without the end frame on a BAD REQUEST: which Kling lines/modes
     * accept image_tail is not a published contract, and a clip without the landing constraint
     * beats no clip at all. A 400 creates no upstream task, so the retry cannot double-render
     * (and storyboard never charges). Any other failure propagates to the caller's handler.
     *
     * @param  array<string,mixed>  $params
     */
    private function submitWithTailFallback(
        object $video,
        string $model,
        string $prompt,
        string $firstFrame,
        array $params,
        ?string $baseUrl,
        StoryboardFrame $frame,
        bool $tailSent,
    ): string {
        try {
            return $video->submitTask($model, $prompt, [$firstFrame], $params, $baseUrl);
        } catch (OpenRouterException $e) {
            if (! $tailSent || $e->errorCode !== OpenRouterException::CODE_BAD_REQUEST) {
                throw $e;
            }

            $frame->update([
                'video_meta' => array_merge($frame->video_meta ?? [], [
                    self::META_TAIL_DROPPED => true,
                    self::META_TAIL_ERROR => $e->getMessage(),
                ]),
            ]);

            unset($params[KlingVideoClient::PARAM_IMAGE_TAIL]);

            return $video->submitTask($model, $prompt, [$firstFrame], $params, $baseUrl);
        }
    }

    /** The IMMEDIATE next frame's signed image url (the shot this clip must land on), or null. */
    private function nextFrameUrl(StoryboardFrame $frame): ?string
    {
        $next = $frame->project->frames()
            ->where('frame_number', (int) $frame->frame_number + 1)
            ->whereNotNull('image_path')
            ->first();

        if ($next === null || ! $this->media->exists($next->image_path)) {
            return null;
        }

        return $this->media->signedUrl($next->image_path);
    }

    /**
     * The clip's duration: the frame's locked shot length (end - start), clamped into the
     * operation's min/max clip bounds. Providers clamp further to their own enums.
     *
     * @param  array<string,mixed>  $params
     */
    private function clipSeconds(StoryboardFrame $frame, array $params): int
    {
        $shot = max(1, (int) $frame->end_second - (int) $frame->start_second);
        $min = max(1, (int) ($params[self::PARAM_MIN_CLIP] ?? self::DEFAULT_MIN_CLIP_SECONDS));
        $max = max($min, (int) ($params[self::PARAM_MAX_CLIP] ?? self::DEFAULT_MAX_CLIP_SECONDS));

        return (int) max($min, min($max, $shot));
    }

    /** The per-model BytePlus region host from the catalog (null = the configured default). */
    private function baseUrl(string $model): ?string
    {
        $url = AiModel::query()
            ->where('operation_key', AiOperation::KEY_STORYBOARD_CLIP)
            ->where('model_id', $model)
            ->value('base_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
