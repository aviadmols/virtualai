<?php

namespace App\Domain\Ai\Contracts;

/**
 * VideoGenerationProvider — the provider seam for ASYNC video-clip generation.
 *
 * Video generation is two-step (submit a task, then poll it to a terminal state), so it is a
 * DISTINCT contract from the synchronous ImageGenerationProvider. One implementation per upstream
 * (BytePlus/Seedance, AtlasCloud). The callers (StoryboardClipGenerator, the pollers) speak ONLY
 * this interface and never know which upstream is behind it — VideoProviderRouter resolves it.
 *
 * pollTask() returns a NORMALIZED task array so every poller reads the SAME shape regardless of
 * upstream: ['status' => succeeded|failed|<processing>, 'content' => ['video_url' => ...],
 * 'error' => ['message' => ...], 'created_at' => int, 'updated_at' => int].
 *
 * Auth + host + error classification stay provider-side (each upstream has its own key, endpoints,
 * and error envelope). No USD cost is returned — video is flat-rate; the caller applies the admin
 * per-clip price. Storyboard clips NEVER charge.
 */
interface VideoGenerationProvider
{
    // Terminal, non-success task states — a poll that sees these stops (a result, not an error).
    // Providers normalize their own failure states onto 'failed' so this set is shared.
    public const TERMINAL_FAILURE = ['failed', 'cancelled', 'expired'];

    /**
     * Submit a video-generation task; returns the upstream task/prediction id. $imageUrls are the
     * optional input frames (image-to-video). $params carries resolution / duration_seconds / ratio.
     *
     * @param  array<int,string>  $imageUrls
     * @param  array<string,mixed>  $params
     */
    public function submitTask(string $model, string $prompt, array $imageUrls, array $params = [], ?string $baseUrl = null): string;

    /**
     * Poll a task and return the NORMALIZED task array (shape above). Never throws for a terminal
     * FAILED task (that is a result); throws only on transport/HTTP errors so the caller reschedules.
     *
     * @return array<string,mixed>
     */
    public function pollTask(string $taskId, ?string $baseUrl = null): array;

    /** True when a polled (normalized) task has completed successfully. */
    public function succeeded(array $task): bool;

    /** Download the result MP4 bytes from the (signed) video url, bounded. Null if unusable. */
    public function downloadVideo(string $url): ?string;
}
