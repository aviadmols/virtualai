<?php

namespace App\Http\Widget;

use Illuminate\Http\UploadedFile;

/**
 * PhotoInput — decode the shopper photo from either a base64 (data-URL or raw) string or
 * a multipart file into raw bytes + a mime type, ready for ImagePayload validation +
 * MediaStorage. Pure decoding; the size/type ceiling is enforced downstream by
 * ImagePayload (the single source of the 5 MiB / allowed-mime contract).
 *
 * Returns null when neither input is present or the base64 is unreadable, so the caller
 * can answer a typed 422 instead of pushing a bad upload to the worker.
 */
final class PhotoInput
{
    // === CONSTANTS ===
    private const DATA_URL_PREFIX = 'data:';
    private const DEFAULT_MIME = 'image/jpeg';

    /** @return array{bytes:string, mime:string}|null */
    public static function decode(?string $base64, ?UploadedFile $file): ?array
    {
        if ($file instanceof UploadedFile) {
            $bytes = (string) $file->get();
            $mime = (string) ($file->getMimeType() ?: self::DEFAULT_MIME);

            return $bytes === '' ? null : ['bytes' => $bytes, 'mime' => $mime];
        }

        if ($base64 === null || $base64 === '') {
            return null;
        }

        return self::fromBase64($base64);
    }

    /** Decode a `data:<mime>;base64,<payload>` URL or a bare base64 string. */
    private static function fromBase64(string $input): ?array
    {
        $mime = self::DEFAULT_MIME;
        $payload = $input;

        if (str_starts_with($input, self::DATA_URL_PREFIX)) {
            // data:image/png;base64,AAAA…
            $comma = strpos($input, ',');

            if ($comma === false) {
                return null;
            }

            $header = substr($input, strlen(self::DATA_URL_PREFIX), $comma - strlen(self::DATA_URL_PREFIX));
            $payload = substr($input, $comma + 1);

            if (preg_match('#^([a-z]+/[a-z0-9.+-]+)#i', $header, $m) === 1) {
                $mime = strtolower($m[1]);
            }
        }

        $bytes = base64_decode(trim($payload), true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }
}
