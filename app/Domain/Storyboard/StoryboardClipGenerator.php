<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\BytePlusVideoClient;
use App\Domain\Media\MediaStorage;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use Throwable;

/**
 * StoryboardClipGenerator — animates a frame's selected image into a short video clip.
 *
 * Uses the storyboard_clip AiOperation (admin-configured Seedance model + params + motion prompt)
 * and submits an image-to-video task via BytePlusVideoClient (the frame image = first_frame). Async:
 * this only SUBMITS + records the task id; PollStoryboardClipJob completes it. NOT a money path.
 */
final class StoryboardClipGenerator
{
    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly BytePlusVideoClient $video,
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
        ]);
        $baseUrl = $this->baseUrl($config->model);

        $frame->update([
            'video_status' => StoryboardFrame::VIDEO_GENERATING,
            'video_poll_attempts' => 0,
            // Video is flat-rate (no inline USD) — carry the per-clip price so the poller records it.
            'video_meta' => ['base_url' => $baseUrl, 'model' => $config->model, 'cost' => $config->flatRatePriceMicroUsd()],
        ]);

        try {
            $taskId = $this->video->submitTask($config->model, $prompt, [$firstFrame], $config->params, $baseUrl);
        } catch (Throwable $e) {
            $frame->update([
                'video_status' => StoryboardFrame::VIDEO_FAILED,
                'video_meta' => array_merge($frame->video_meta ?? [], ['error' => $e->getMessage()]),
            ]);

            return false;
        }

        $frame->update(['video_task_id' => $taskId]);

        return true;
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
