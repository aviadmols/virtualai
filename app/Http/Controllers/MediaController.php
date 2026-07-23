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
 *  - PUBLIC media — cacheable, no signature. Only two path families are public: banner objects
 *    (marketing shown to every shopper) and platform media-assets (Super-Admin fonts/media at
 *    stable URLs). Anything else 404s, so a private path can never leak through this door.
 *  - PRIVATE media — an expiring SIGNED URL (try-on sources/results); a bad/absent signature 403s.
 *
 * The disk sandboxes reads to its root, so a "../" traversal in the path can never escape it.
 */
final class MediaController extends Controller
{
    // === CONSTANTS ===
    // The only public path families: banner objects + platform media-assets.
    private const PUBLIC_BANNER_MARKER = '/banners/';

    private const PUBLIC_ASSETS_PREFIX = 'media-assets/';

    // A public object never changes at its path -> cache hard.
    private const CACHE_HEADER = 'public, max-age=31536000, immutable';

    // Fonts loaded via @font-face are CORS-gated by browsers; public assets are
    // world-readable anyway, so a wildcard origin is safe (no credentials involved).
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Cross-Origin-Resource-Policy' => 'cross-origin',
    ];

    // SVG can carry script; sandboxing neutralises it when the URL is opened directly
    // (admin-uploaded only, but the media URL shares the panels' origin — defense in depth).
    private const SVG_EXTENSION = 'svg';

    private const SVG_CSP_HEADER = 'sandbox';

    /** Public object — cacheable, no signature. Refuses any non-public path family. */
    public function public(string $path): StreamedResponse
    {
        abort_unless($this->isPublicPath($path), 404);

        $disk = $this->disk();
        abort_unless($disk->exists($path), 404);

        $headers = ['Cache-Control' => self::CACHE_HEADER] + self::CORS_HEADERS;

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === self::SVG_EXTENSION) {
            $headers['Content-Security-Policy'] = self::SVG_CSP_HEADER;
        }

        return $disk->response($path, headers: $headers);
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

    private function isPublicPath(string $path): bool
    {
        return str_contains($path, self::PUBLIC_BANNER_MARKER)
            || str_starts_with($path, self::PUBLIC_ASSETS_PREFIX);
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('trayon.media.disk'));

        return $disk;
    }
}
