<?php

namespace App\Domain\Ai;

/**
 * ImagePayload — a validated image input for a multimodal call.
 *
 * Wraps either a base64 data URL (shopper photo / product image bytes) or a real
 * https:// URL (a signed CDN url — preferred, base64 inflates the payload ~33%
 * and multiplies worker memory). Validates mime + size BEFORE the request so a
 * 12 MB photo can never 413 the request or OOM the worker (the locked scar). The
 * size ceiling (MAX_IMAGE_BYTES) is coordinated with widget-embed + pdp-scanner.
 */
final readonly class ImagePayload
{
    // === CONSTANTS ===
    // Max raw image bytes accepted for a base64 input. Coordinated with
    // widget-embed (upload limit) + pdp-scanner (screenshot size).
    public const MAX_IMAGE_BYTES = 5_242_880; // 5 MiB

    public const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private function __construct(
        public string $url,        // a data: URL or an https: URL
        public bool $isRemote,
    ) {}

    /**
     * Build from raw bytes, validating mime + size. Throws a classified
     * bad_request (our bug to fix) when the input is too large or wrong type —
     * never lets it reach OpenRouter.
     */
    public static function fromBytes(string $bytes, string $mime): self
    {
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                sprintf('Unsupported image mime "%s"; allowed: %s.', $mime, implode(', ', self::ALLOWED_MIME)),
            );
        }

        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                sprintf('Image too large (%d bytes > %d max); downscale before send.', strlen($bytes), self::MAX_IMAGE_BYTES),
            );
        }

        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);

        return new self($dataUrl, false);
    }

    /** Build from a real (preferably signed) https URL — preferred over base64. */
    public static function fromUrl(string $url): self
    {
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            throw OpenRouterException::make(
                OpenRouterException::CODE_BAD_REQUEST,
                'Image url must be an http(s) URL.',
            );
        }

        return new self($url, true);
    }

    /** The OpenRouter image_url content part. */
    public function toContentPart(): array
    {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => $this->url],
        ];
    }
}
