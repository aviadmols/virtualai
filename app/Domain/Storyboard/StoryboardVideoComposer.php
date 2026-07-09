<?php

namespace App\Domain\Storyboard;

use App\Domain\Media\MediaStorage;
use App\Models\StoryboardProject;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * StoryboardVideoComposer — stitch a project's frame images, in order, into ONE MP4 (ffmpeg).
 *
 * Each frame is shown for an equal slice of the requested total length, scaled + letterboxed to
 * the target resolution (derived from the project aspect + the chosen height). Images-only (not
 * the per-frame AI clips) so the output length is exact and deterministic. Admin-only; no charge.
 */
final class StoryboardVideoComposer
{
    // === CONSTANTS ===
    private const FFMPEG_BINARY = 'FFMPEG_BINARY';
    private const FFMPEG_DEFAULT = 'ffmpeg';

    private const FPS = 30;
    private const MIN_PER_FRAME = 0.4;      // never flash a frame shorter than this
    private const PROCESS_TIMEOUT = 100;    // seconds — under the media worker timeout

    // Output height per resolution key; width is derived from the project aspect ratio.
    private const HEIGHTS = ['720p' => 720, '1080p' => 1080];
    private const DEFAULT_RESOLUTION = '1080p';
    private const DEFAULT_ASPECT = '16:9';

    private const OUTPUT_MIME = 'video/mp4';
    private const DEFAULT_IMAGE_EXT = 'png';

    public function __construct(
        private readonly MediaStorage $media,
    ) {}

    /**
     * Build the combined video and return its stored disk path.
     *
     * @throws RuntimeException when there are no frame images or ffmpeg fails.
     */
    public function compose(StoryboardProject $project, int $seconds, string $resolution): string
    {
        $paths = $project->frames()->whereNotNull('image_path')->pluck('image_path')->all();

        if ($paths === []) {
            throw new RuntimeException('No generated frame images to combine.');
        }

        [$width, $height] = $this->dimensions($project->aspect_ratio ?? self::DEFAULT_ASPECT, $resolution);
        $perFrame = max(self::MIN_PER_FRAME, round($seconds / count($paths), 3));

        $dir = sys_get_temp_dir().'/sb_'.$project->id.'_'.Str::random(10);
        File::ensureDirectoryExists($dir);

        try {
            $inputs = $this->downloadFrames($paths, $dir);
            $output = $dir.'/final.mp4';

            $result = Process::timeout(self::PROCESS_TIMEOUT)
                ->run($this->command($inputs, $perFrame, $width, $height, $output));

            if (! $result->successful() || ! is_file($output)) {
                throw new RuntimeException($this->trimError($result->errorOutput() ?: $result->output()));
            }

            $stored = $this->media->storeStoryboardVideo($project->id, (string) file_get_contents($output));

            return $stored->path;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Write each source frame to the temp dir; returns the ordered local file paths. */
    private function downloadFrames(array $paths, string $dir): array
    {
        $files = [];

        foreach ($paths as $i => $path) {
            $bytes = $this->media->get($path);
            if ($bytes === null) {
                continue; // an image that vanished from the disk is skipped, not fatal
            }

            $ext = pathinfo((string) $path, PATHINFO_EXTENSION) ?: self::DEFAULT_IMAGE_EXT;
            $file = $dir.'/frame_'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).'.'.$ext;
            File::put($file, $bytes);
            $files[] = $file;
        }

        if ($files === []) {
            throw new RuntimeException('Frame images are not available on the media disk (check MEDIA_DISK persistence).');
        }

        return $files;
    }

    /**
     * The ffmpeg argv: one still input per frame (looped for $perFrame s), each scaled + padded to
     * the target box, then concatenated into a single H.264 stream.
     *
     * @param  array<int,string>  $inputs
     * @return array<int,string>
     */
    private function command(array $inputs, float $perFrame, int $width, int $height, string $output): array
    {
        $args = [$this->binary(), '-y'];

        foreach ($inputs as $file) {
            $args[] = '-loop';
            $args[] = '1';
            $args[] = '-t';
            $args[] = (string) $perFrame;
            $args[] = '-i';
            $args[] = $file;
        }

        $filter = '';
        $count = count($inputs);
        for ($i = 0; $i < $count; $i++) {
            $filter .= "[{$i}:v]scale={$width}:{$height}:force_original_aspect_ratio=decrease,"
                ."pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=".self::FPS."[v{$i}];";
        }
        for ($i = 0; $i < $count; $i++) {
            $filter .= "[v{$i}]";
        }
        $filter .= "concat=n={$count}:v=1:a=0,format=yuv420p[v]";

        return array_merge($args, [
            '-filter_complex', $filter,
            '-map', '[v]',
            '-r', (string) self::FPS,
            '-movflags', '+faststart',
            $output,
        ]);
    }

    /** Target WxH from "w:h" aspect + a resolution key; both dimensions forced even (H.264). */
    private function dimensions(string $aspect, string $resolution): array
    {
        $height = self::HEIGHTS[$resolution] ?? self::HEIGHTS[self::DEFAULT_RESOLUTION];

        [$aw, $ah] = array_pad(array_map('intval', explode(':', $aspect)), 2, 0);
        if ($aw <= 0 || $ah <= 0) {
            [$aw, $ah] = array_map('intval', explode(':', self::DEFAULT_ASPECT));
        }

        $width = (int) round($height * $aw / $ah);

        return [$this->even($width), $this->even($height)];
    }

    private function even(int $n): int
    {
        return $n % 2 === 0 ? $n : $n + 1;
    }

    private function binary(): string
    {
        return (string) (env(self::FFMPEG_BINARY) ?: self::FFMPEG_DEFAULT);
    }

    private function trimError(string $error): string
    {
        return Str::limit(trim($error), 500);
    }
}
