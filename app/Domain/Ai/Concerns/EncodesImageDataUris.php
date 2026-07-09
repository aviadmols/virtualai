<?php

namespace App\Domain\Ai\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * EncodesImageDataUris — download an image url and re-send it as a base64 data URI.
 *
 * Shared by providers that must FETCH their input images (FAL, AtlasCloud-style task APIs): the
 * media disk may not be publicly reachable, so any http(s) url is downloaded here and inlined as
 * `data:<mime>;base64,...` (documented as supported by these providers). A non-http entry (already
 * a data URI) passes through unchanged. Requires a `$http` HttpFactory property on the user.
 */
trait EncodesImageDataUris
{
    // === CONSTANTS ===
    private const IMAGE_FETCH_TIMEOUT = 30;

    private const MAX_INPUT_IMAGE_BYTES = 12_582_912; // 12 MiB per input-image download ceiling

    private const DEFAULT_INPUT_IMAGE_MIME = 'image/png';

    /** An http(s) url becomes a data URI; anything else passes through. Null when unusable. */
    private function asDataUri(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            return $url;
        }

        try {
            $response = $this->http->timeout(self::IMAGE_FETCH_TIMEOUT)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > self::MAX_INPUT_IMAGE_BYTES) {
            return null;
        }

        return 'data:'.$this->dataUriMime($response).';base64,'.base64_encode($bytes);
    }

    /**
     * Convert every http(s) entry of a url list to a data URI, dropping unusable ones.
     *
     * @param  array<int,string>  $urls
     * @return array<int,string>
     */
    private function asDataUris(array $urls): array
    {
        $out = [];

        foreach (array_values($urls) as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $converted = $this->asDataUri($url);
            if ($converted !== null) {
                $out[] = $converted;
            }
        }

        return $out;
    }

    /** The image mime from the response Content-Type, defaulting to PNG for non-image types. */
    private function dataUriMime(Response $response): string
    {
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return str_starts_with($type, 'image/') ? $type : self::DEFAULT_INPUT_IMAGE_MIME;
    }
}
