<?php

namespace App\Domain\Media;

/**
 * StoredMedia — an opaque reference to one stored object on the media disk.
 *
 * Carries the disk PATH (the opaque key persisted on a generation — never a public
 * URL), the mime, and the byte size for masked logging. A browser-facing URL is
 * minted on demand and short-lived (MediaStorage::signedUrl); the path itself is
 * never handed to the widget.
 */
final readonly class StoredMedia
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public int $byteSize,
    ) {}
}
