<?php

namespace App\Http\Widget;

use App\Models\EndUser;
use App\Models\Site;

/**
 * EndUserResolver — resolve (or create) the EndUser for a widget request from the
 * opaque browser anon_token, scoped to (site_id, anon_token).
 *
 * The browser sends ONLY an opaque anon_token; the server never trusts any other
 * client-sent identity. One row per (site_id, anon_token) so the free-tries counter +
 * the session gallery survive navigation between a site's PDPs (ARCHITECTURE.md lead
 * gate). Must run inside the bound tenant — BelongsToAccount stamps account_id and the
 * global scope keeps the lookup account-scoped, so site A can never resolve site B's
 * end user.
 */
final class EndUserResolver
{
    /**
     * Find the existing end user for this token within the site, or create a fresh NEW
     * lead. Touches last_seen_at on every interaction. The account_id is auto-stamped by
     * BelongsToAccount from the bound tenant — never read from the request.
     */
    public function resolve(Site $site, string $anonToken): EndUser
    {
        $endUser = EndUser::query()
            ->where('site_id', $site->getKey())
            ->where('anon_token', $anonToken)
            ->first();

        if ($endUser === null) {
            $endUser = new EndUser([
                'site_id' => $site->getKey(),
                'anon_token' => $anonToken,
                'last_seen_at' => now(),
            ]);
            $endUser->save();

            return $endUser;
        }

        $endUser->forceFill(['last_seen_at' => now()])->save();

        return $endUser;
    }

    /**
     * Find an EXISTING end user for this token within the site, or null. Used by the
     * read endpoints (poll / gallery) which must not mint a new lead just to read.
     */
    public function find(Site $site, string $anonToken): ?EndUser
    {
        return EndUser::query()
            ->where('site_id', $site->getKey())
            ->where('anon_token', $anonToken)
            ->first();
    }
}
