<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\StoryboardTextCaller;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * StoryboardVideoDirector — the intermediate "director" pass before a one-call video generation.
 *
 * A multimodal model receives the ACTUAL generated frame images in shot order (image N is shot
 * N's spatial ground truth) plus the storyboard data — per-shot timings rescaled to the requested
 * length, camera/composition/action/motion, dialogue, the visual bible and the character bible —
 * and composes ONE precise cinematic prompt: a bracketed timed shot list with motivated
 * transitions and locked spatial continuity. Uses the storyboard_video_director AiOperation
 * (admin-editable model + prompts). NOT a money path — never charges. ANY failure returns null so
 * the caller falls back to the deterministic auto prompt; the director can never block a video.
 */
final class StoryboardVideoDirector
{
    // === CONSTANTS ===
    private const KEY_VIDEO_PROMPT = 'video_prompt';

    // Keep the composed prompt under the tightest live video-model budget (fal's happy-horse caps
    // the prompt at 2500 chars); the fal client's schema-driven truncation is the hard guard.
    public const MAX_PROMPT_CHARS = 2300;

    // Character/analysis lines fed to the model, capped (same cap as the auto prompt).
    public const MAX_PROMPT_CHARACTERS = 5;

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly StoryboardTextCaller $caller,
    ) {}

    /**
     * Compose the final video prompt from the storyboard, or null on ANY failure (unseeded
     * operation, provider outage, invalid JSON, empty output) — the caller must fall back.
     *
     * @param  array<int,string>  $frameImageUrls  the SAME signed urls the video submit will send, in shot order
     */
    public function compose(StoryboardProject $project, array $frameImageUrls, int $totalSeconds, string $resolution, string $ratio): ?string
    {
        try {
            return $this->composeOrFail($project, $frameImageUrls, $totalSeconds, $resolution, $ratio);
        } catch (Throwable $e) {
            Log::warning('storyboard.director.failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function composeOrFail(StoryboardProject $project, array $frameImageUrls, int $totalSeconds, string $resolution, string $ratio): ?string
    {
        $shotLines = self::shotLines($project, $totalSeconds);
        if ($shotLines === []) {
            return null;
        }

        $pipeline = is_array($project->pipeline) ? $project->pipeline : [];
        $bible = $pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] ?? null;
        $bible = is_array($bible) ? $bible : [];

        $config = $this->resolver->for(AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR);
        $result = $this->caller->extract($config, [
            'story' => trim((string) $project->story_idea),
            'total_seconds' => $totalSeconds,
            'shot_count' => count($shotLines),
            'shot_list' => implode("\n", $shotLines),
            'global_style' => trim((string) ($bible['global_style'] ?? '')),
            'continuity_rules' => trim((string) ($bible['continuity_rules'] ?? '')),
            'characters' => self::characterLine($pipeline),
            'reference_analyses' => self::referenceAnalysesLine($project),
            'aspect_ratio' => $ratio,
            'resolution' => $resolution,
            'max_chars' => self::MAX_PROMPT_CHARS,
        ], $frameImageUrls);

        $prompt = trim((string) ($result->json[self::KEY_VIDEO_PROMPT] ?? ''));

        return $prompt === '' ? null : mb_substr($prompt, 0, self::MAX_PROMPT_CHARS);
    }

    /**
     * The numbered, timed shot lines shared by the director input and the auto-prompt fallback.
     * Timings are RESCALED from the planned frame windows to the REQUESTED total so the prompt's
     * clock matches what the video model will actually render; the last shot always ends exactly
     * at the total. Frames without planned timings split the total evenly.
     *
     * @return array<int,string>
     */
    public static function shotLines(StoryboardProject $project, int $totalSeconds): array
    {
        $frames = $project->frames()->orderBy('frame_number')->get()->values();
        $count = $frames->count();
        if ($count === 0 || $totalSeconds <= 0) {
            return [];
        }

        $plannedEnd = (int) $frames->max('end_second');
        $lines = [];

        foreach ($frames as $index => $frame) {
            if ($plannedEnd > 0) {
                $start = (int) round(((int) $frame->start_second) * $totalSeconds / $plannedEnd);
                $end = (int) round(((int) $frame->end_second) * $totalSeconds / $plannedEnd);
            } else {
                $start = (int) round($index * $totalSeconds / $count);
                $end = (int) round(($index + 1) * $totalSeconds / $count);
            }

            $lines[] = self::shotLine($index + 1, min($start, $totalSeconds), $index === $count - 1 ? $totalSeconds : min($end, $totalSeconds), $frame);
        }

        return $lines;
    }

    /** "Shot 3 [00:06-00:09] — description | camera: … | action: … | says: "…"" (empty fields omitted). */
    private static function shotLine(int $number, int $start, int $end, StoryboardFrame $frame): string
    {
        $fields = array_values(array_filter([
            trim((string) $frame->description),
            self::labelled('camera', $frame->camera_angle),
            self::labelled('composition', $frame->composition),
            self::labelled('action', $frame->action),
            self::labelled('motion', $frame->motion_prompt),
            filled($frame->dialogue) ? 'says: "'.trim((string) $frame->dialogue).'"' : null,
        ], static fn (?string $field): bool => $field !== null && $field !== ''));

        return sprintf('Shot %d [%s-%s] — %s', $number, self::clock($start), self::clock($end), implode(' | ', $fields));
    }

    /**
     * "Name — description; …" for the pipeline's character bible, capped. Empty when none exist.
     *
     * @param  array<string,mixed>  $pipeline
     */
    public static function characterLine(array $pipeline): string
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

    /** "@tag (type): description; …" for the analyzed reference uploads, capped. Empty when none. */
    public static function referenceAnalysesLine(StoryboardProject $project): string
    {
        return $project->assets()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('id')
            ->limit(self::MAX_PROMPT_CHARACTERS)
            ->get()
            ->map(static fn ($asset): string => '@'.$asset->tag.' ('.$asset->type.'): '.trim((string) $asset->description))
            ->implode('; ');
    }

    private static function labelled(string $label, ?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $label.': '.$value;
    }

    private static function clock(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
