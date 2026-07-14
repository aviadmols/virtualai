<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Media\MediaStorage;
use App\Http\Widget\OwnedGenerationResolver;
use App\Http\Widget\WidgetContext;
use App\Http\Widget\WidgetResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GenerationImageController — GET /widget/v1/generations/{id}/image.
 *
 * The SAME-ORIGIN bytes door for one succeeded try-on result, and it exists for exactly one
 * reason: navigator.share({files:[File]}) needs the image BYTES, not an <img> src. The stored
 * result lives on the media origin (S3/R2/CDN), which answers no CORS to a storefront — so the
 * widget can display it but can never fetch() it into a Blob. This route sits on the widget API
 * behind ResolveWidgetSite, which already returns the CORS headers for the site's allow-listed
 * Origin, so the widget CAN fetch it, build a File, and share.
 *
 * IT IS NOT A NEW PRIVACY SURFACE. The ownership rule is the SAME one the poll uses
 * (OwnedGenerationResolver): the bound account + this site + this anon_token's end user. A
 * try-on result is a photo of a stranger's body; the only party allowed to read it is the
 * shopper who made it.
 *
 * FOUR "no" answers, ONE response: another site's generation, another shopper's generation on
 * this site, a generation that is not succeeded, and a result whose bytes are gone (retention)
 * all return the identical flat 404 — never a 403 with detail, never a distinct message. A
 * different answer for "exists but is not yours" would itself confirm the id.
 *
 * Reads only: no credit is touched, no row is written, no signed URL is minted. The bytes are
 * PRIVATE + no-store, so no proxy or shared cache may ever hold one shopper's result and hand
 * it to the next request that looks like it.
 */
final class GenerationImageController
{
    // === CONSTANTS ===
    private const QUERY_ANON_TOKEN = 'anon_token';

    // Private + never stored: a shared cache holding a body photo is the whole nightmare.
    private const CACHE_CONTROL = 'private, no-store, max-age=0';

    private const NO_SNIFF = 'nosniff';

    private const ERROR_NOT_FOUND = 'generation_not_found';

    private const MESSAGE_NOT_FOUND = 'widget_api.not_found.generation';

    public function __construct(
        private readonly OwnedGenerationResolver $generations,
        private readonly MediaStorage $media,
    ) {}

    public function __invoke(Request $request, int $id): Response
    {
        $site = WidgetContext::of($request)->site;

        $generation = $this->generations->resolve(
            $site,
            (string) $request->query(self::QUERY_ANON_TOKEN, ''),
            $id,
        );

        // Not this shopper's, not this site's, or never succeeded -> the one answer.
        if ($generation === null || ! $generation->isSucceeded()) {
            return $this->notFound();
        }

        $bytes = $this->media->stream($generation->result_image_path, [
            'Cache-Control' => self::CACHE_CONTROL,
            'X-Content-Type-Options' => self::NO_SNIFF,
        ]);

        // Succeeded, but the object is gone (retention purge) — same flat 404, never a 500.
        return $bytes ?? $this->notFound();
    }

    private function notFound(): Response
    {
        return WidgetResponse::error(
            self::ERROR_NOT_FOUND,
            __(self::MESSAGE_NOT_FOUND),
            WidgetResponse::STATUS_NOT_FOUND,
        );
    }
}
