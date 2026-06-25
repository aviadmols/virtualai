<?php

namespace App\Http\Widget;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * WidgetContext — the resolved auth context for one widget request.
 *
 * The widget-auth middleware resolves the Site from the site_key + Origin allow-list,
 * binds the tenant, and stashes this on the request (request attributes). Controllers
 * read it via WidgetContext::of($request) instead of re-resolving — the account is the
 * site's account, NEVER the request body.
 */
final readonly class WidgetContext
{
    // === CONSTANTS ===
    public const REQUEST_ATTRIBUTE = 'tray_widget_context';

    public function __construct(
        public Site $site,
        public string $origin,
    ) {}

    public function accountId(): int
    {
        return (int) $this->site->account_id;
    }

    public function siteId(): int
    {
        return (int) $this->site->getKey();
    }

    /** Stash this context on the request for the controllers behind the middleware. */
    public function bindTo(Request $request): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $this);
    }

    /** Read the context the middleware stashed; fails loud if the middleware did not run. */
    public static function of(Request $request): self
    {
        $context = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! $context instanceof self) {
            throw new \RuntimeException('WidgetContext missing: the widget-auth middleware did not run.');
        }

        return $context;
    }
}
