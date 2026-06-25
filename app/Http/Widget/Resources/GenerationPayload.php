<?php

namespace App\Http\Widget\Resources;

use App\Domain\Media\MediaStorage;
use App\Models\Generation;

/**
 * GenerationPayload — the PUBLIC shape of a try-on for the widget's poll + gallery.
 *
 * Carries the status, and ONLY when succeeded a SHORT-lived SIGNED result URL minted on
 * demand (never the opaque disk path, never a public URL). The failure_code is surfaced
 * so the widget can render the right screen, but the source/result PATHS, the cost, the
 * charge ledger id, and any tenant internal are NEVER serialized.
 */
final class GenerationPayload
{
    public static function make(Generation $generation, MediaStorage $media): array
    {
        $succeeded = $generation->isSucceeded();

        return [
            'id' => (int) $generation->getKey(),
            'status' => $generation->status,
            'failure_code' => $generation->failure_code,
            // A signed, expiring URL only once the result is stored + the row succeeded.
            'result_url' => $succeeded ? $media->signedUrl($generation->result_image_path) : null,
            'created_at' => optional($generation->created_at)->toIso8601String(),
        ];
    }
}
