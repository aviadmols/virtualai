<?php

namespace App\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves media stored on a LOCAL disk (a Railway Volume). The mount lives outside public/, so
 * the app streams the bytes. Only reached when MEDIA_DISK is a local/volume disk — the object
 * store path (s3/r2) serves via its CDN + native signed URLs and never routes here.
 *
 * Two doors:
 *  - PUBLIC banner media — cacheable, no signature (marketing shown to every shopper). The door
 *    refuses anything that is not a banner object, so a private path can never leak through it.
 *  - PRIVATE media — an expiring SIGNED URL (try-on sources/results); a bad/absent signature 403s.
 *
 * The disk sandboxes reads to its root, so a "../" traversal in the path can never escape it.
 */
final class MediaController extends Controller
{
    // === CONSTANTS ===
    // Only banner objects are public; every public path carries this segment.
    private const PUBLIC_MARKER = '/banners/';

    // A generated banner never changes at its (random) path -> cache hard.
    private const CACHE_HEADER = 'public, max-age=31536000, immutable';

    /** Public banner object — cacheable, no signature. Refuses non-banner (private) paths. */
    public function public(string $path): StreamedResponse
    {
        abort_unless(str_contains($path, self::PUBLIC_MARKER), 404);

        $disk = $this->disk();
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => self::CACHE_HEADER]);
    }

    /** Private object behind an expiring signature (the widget receives the signed URL). */
    public function signed(Request $request): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $path = (string) $request->query('path', '');
        $disk = $this->disk();
        abort_if($path === '' || ! $disk->exists($path), 404);

        return $disk->response($path);
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('trayon.media.disk'));

        return $disk;
    }
}
