<?php

namespace App\Http\Widget;

use App\Models\Generation;
use App\Models\Site;

/**
 * OwnedGenerationResolver — THE ownership rule for reading one generation from the widget.
 *
 * A try-on result is a photo of a person's body. It may be read by exactly one party: the
 * shopper who made it. So a generation resolves only when it belongs to
 *   (the bound ACCOUNT — via the BelongsToAccount global scope)
 *   AND the resolved SITE
 *   AND the EndUser behind this request's anon_token.
 * Anything else resolves to NULL and the caller answers a flat 404 — never a 403 with
 * detail, never a distinct message for "exists but is someone else's" (that alone would
 * confirm the id).
 *
 * ONE rule, ONE place: the poll (status + signed URL) and the same-origin image-bytes door
 * both call this, so a second reader can never grow a weaker second check.
 */
final class OwnedGenerationResolver
{
    // === CONSTANTS ===
    // The shortest anon_token worth a lookup. The widget mints a long opaque token; a short
    // one is a stub/probe and is refused before it ever reaches a query.
    public const MIN_ANON_TOKEN_LENGTH = 8;

    public function __construct(
        private readonly EndUserResolver $endUsers,
    ) {}

    /**
     * The generation owned by (bound account, site, this anon_token's end user) — or null.
     * Read-only: it never mints a lead just to read (EndUserResolver::find, not resolve).
     */
    public function resolve(Site $site, string $anonToken, int $generationId): ?Generation
    {
        if (strlen($anonToken) < self::MIN_ANON_TOKEN_LENGTH) {
            return null;
        }

        $endUser = $this->endUsers->find($site, $anonToken);

        if ($endUser === null) {
            return null;
        }

        return Generation::query()
            ->where('site_id', $site->getKey())
            ->where('end_user_id', $endUser->getKey())
            ->whereKey($generationId)
            ->first();
    }
}
