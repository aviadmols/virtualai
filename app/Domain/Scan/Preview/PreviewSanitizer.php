<?php

namespace App\Domain\Scan\Preview;

/**
 * PreviewSanitizer — turn a fetched merchant page into SAFE HTML for a sandboxed placement
 * preview, and inject our element-picker.
 *
 * The preview renders inside `<iframe srcdoc sandbox="allow-scripts">` (no allow-same-origin →
 * opaque origin, cannot touch the admin session). This is defence-in-depth on top of that: it
 * removes everything executable/navigational/refreshing from the merchant HTML so only OUR picker
 * runs, then injects a `<base href>` (so the store's relative CSS/img/fonts still resolve) and the
 * picker script. Crucially it KEEPS `<style>`/`<link>` so the preview looks like the real page.
 *
 * Pure + side-effect free (no network, no filesystem) → fully unit-testable.
 */
final class PreviewSanitizer
{
    // === CONSTANTS ===
    // Subtrees removed wholesale — executable or external-content tags. <style>/<link> are KEPT
    // (the preview must look like the live page); only these are stripped.
    private const STRIP_TAGS = ['script', 'noscript', 'template', 'iframe', 'object', 'embed', 'applet'];

    // Injected into <head>: force UTF-8 (we transcode the body to it) + base
    // (relative-asset resolution to the live store) + no-referrer.
    private const CHARSET_META = '<meta charset="utf-8">';
    private const REFERRER_META = '<meta name="referrer" content="no-referrer">';

    // The store's JS is stripped for safety, so any "hide until JS loads" pattern would leave the
    // preview BLANK (white): a fixed preloader overlay, or body{opacity:0}/visibility:hidden that a
    // load handler normally clears. This reveal sheet neutralizes the common cases — force the page
    // visible + hide well-known preloader/progress widgets. Injected LAST so it wins the cascade.
    private const REVEAL_STYLE =
        '<style>html,body{opacity:1!important;visibility:visible!important;overflow:auto!important;height:auto!important}'
        .'[class*="preload" i],[id*="preload" i],[class*="page-loading" i],[id*="page-loading" i],'
        .'[class*="loading-screen" i],[id*="loading-screen" i],[class*="site-loader" i],[id*="site-loader" i],'
        .'[class*="loader-wrapper" i],[id*="loader-wrapper" i],.pace,.pace-running,#nprogress{display:none!important}'
        .'</style>';

    public function sanitize(string $html, string $baseHref, string $pickerScript): string
    {
        // Normalize to valid UTF-8 FIRST: many storefronts (esp. Hebrew IL shops)
        // serve windows-1255/latin bytes. Left raw they render as mojibake in the
        // iframe AND break the json_encode Livewire runs on every re-render (500).
        $html = $this->toUtf8($html);
        $html = $this->stripTags($html);
        $html = $this->stripEventHandlers($html);
        $html = $this->stripJavascriptUris($html);
        $html = $this->stripMetaRefresh($html);
        $html = $this->stripExistingBase($html);
        $html = $this->stripCharsetMeta($html);
        $html = $this->injectHead($html, $baseHref);
        $html = $this->injectRevealStyle($html);
        $html = $this->injectPicker($html, $pickerScript);

        return $html;
    }

    /**
     * Coerce arbitrary page bytes into valid UTF-8. Best-effort transcode from a
     * declared non-UTF-8 charset (iconv handles windows-1255/latin); then a hard
     * pass that drops any remaining invalid byte sequences so the output is ALWAYS
     * valid UTF-8 — safe to embed in the iframe and to json_encode.
     */
    private function toUtf8(string $html): string
    {
        if ($html === '' || mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $charset = $this->declaredCharset($html);

        if ($charset !== null && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $html);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $html);

        if (is_string($clean) && mb_check_encoding($clean, 'UTF-8')) {
            return $clean;
        }

        return mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    }

    /** The charset declared in a <meta charset> / <meta http-equiv content-type>, or null. */
    private function declaredCharset(string $html): ?string
    {
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9\-]+)/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Drop the merchant's own charset declarations — we force UTF-8 to match our transcode. */
    private function stripCharsetMeta(string $html): string
    {
        $html = preg_replace('#<meta\b[^>]*charset\s*=\s*["\']?[a-z0-9\-]+["\']?[^>]*>#is', '', $html) ?? $html;

        return preg_replace('#<meta\b[^>]*http-equiv\s*=\s*["\']?\s*content-type[^>]*>#is', '', $html) ?? $html;
    }

    /** Remove executable / external-content subtrees (and their unclosed variants). */
    private function stripTags(string $html): string
    {
        foreach (self::STRIP_TAGS as $tag) {
            $html = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', '', $html) ?? $html;
            $html = preg_replace('#<'.$tag.'\b[^>]*/?>#is', '', $html) ?? $html;
        }

        return $html;
    }

    /** Remove inline event handlers (on*="…" / '…' / unquoted) in any quote style. */
    private function stripEventHandlers(string $html): string
    {
        $html = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $html) ?? $html;
        $html = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $html) ?? $html;

        return $html;
    }

    /** Neutralize javascript: URIs on the navigational/asset attributes. */
    private function stripJavascriptUris(string $html): string
    {
        $html = preg_replace('/\b(href|src|action|xlink:href)\s*=\s*"\s*javascript:[^"]*"/i', '$1="#"', $html) ?? $html;
        $html = preg_replace("/\b(href|src|action|xlink:href)\s*=\s*'\s*javascript:[^']*'/i", "$1='#'", $html) ?? $html;

        return $html;
    }

    /** Remove <meta http-equiv="refresh"> so the preview can't auto-navigate. */
    private function stripMetaRefresh(string $html): string
    {
        return preg_replace('#<meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh[^>]*>#is', '', $html) ?? $html;
    }

    /** Remove the merchant's own <base> — we set our own for correct asset resolution. */
    private function stripExistingBase(string $html): string
    {
        return preg_replace('#<base\b[^>]*>#is', '', $html) ?? $html;
    }

    /**
     * Inject our <base href> + no-referrer meta right after <head> (or at the top when there is
     * no head). substr_replace (not preg_replace) is used so the URL — arbitrary text that may
     * contain $ or \ — is inserted literally, never interpreted as a replacement backreference.
     */
    private function injectHead(string $html, string $baseHref): string
    {
        $inject = self::CHARSET_META
            .'<base href="'.htmlspecialchars($baseHref, ENT_QUOTES).'">'
            .self::REFERRER_META;

        if (preg_match('#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);

            return substr_replace($html, $inject, $at, 0);
        }

        return $inject.$html;
    }

    /**
     * Inject the reveal stylesheet as LATE as possible (before </body>, else appended) so it wins
     * the cascade over the store's own "hide until loaded" rules — otherwise a preloader/opacity
     * overlay would show the merchant a blank white preview (the JS that clears it is stripped).
     */
    private function injectRevealStyle(string $html): string
    {
        $at = stripos($html, '</body>');

        if ($at !== false) {
            return substr_replace($html, self::REVEAL_STYLE, $at, 0);
        }

        return $html.self::REVEAL_STYLE;
    }

    /** Inject the picker as the ONLY script, before </body> (or appended when there is none). */
    private function injectPicker(string $html, string $pickerScript): string
    {
        $tag = '<script>'.$pickerScript.'</script>';
        $at = stripos($html, '</body>');

        if ($at !== false) {
            return substr_replace($html, $tag, $at, 0);
        }

        return $html.$tag;
    }
}
