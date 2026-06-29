<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the active UI locale for the admin panels and exposes the text
 * direction so Filament renders <html dir="rtl" lang="he"> for Hebrew and
 * <html dir="ltr" lang="en"> otherwise.
 *
 * The locale is chosen by bezhansalleh/filament-language-switch (stored in the
 * session). This middleware reads that choice (with a ?locale= override for the
 * Playwright RTL gate) and sets the app locale BEFORE the panel renders so every
 * __() string and Filament's own direction resolve correctly. The whole admin
 * mirrors because every component uses CSS logical properties — direction is a
 * flip, not a rewrite.
 */
class HtmlDirection
{
    // === CONSTANTS ===
    public const SUPPORTED = ['en', 'he'];
    public const RTL_LOCALES = ['he'];
    public const SESSION_KEY = 'locale';
    public const QUERY_KEY = 'locale';
    public const DEFAULT_LOCALE = 'en';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);
        Session::put(self::SESSION_KEY, $locale);

        // Shared with Blade/Filament render hooks that need the raw dir value.
        $request->attributes->set('ui_dir', $this->directionFor($locale));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $candidate = $request->query(self::QUERY_KEY)
            ?? Session::get(self::SESSION_KEY)
            ?? App::getLocale();

        return in_array($candidate, self::SUPPORTED, true)
            ? $candidate
            : self::DEFAULT_LOCALE;
    }

    public static function directionFor(string $locale): string
    {
        return in_array($locale, self::RTL_LOCALES, true) ? 'rtl' : 'ltr';
    }
}
