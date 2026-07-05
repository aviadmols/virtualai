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

    // Injected into <head>: base (relative-asset resolution to the live store) + no-referrer.
    private const REFERRER_META = '<meta name="referrer" content="no-referrer">';

    public function sanitize(string $html, string $baseHref, string $pickerScript): string
    {
        $html = $this->stripTags($html);
        $html = $this->stripEventHandlers($html);
        $html = $this->stripJavascriptUris($html);
        $html = $this->stripMetaRefresh($html);
        $html = $this->stripExistingBase($html);
        $html = $this->injectHead($html, $baseHref);
        $html = $this->injectPicker($html, $pickerScript);

        return $html;
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
        $inject = '<base href="'.htmlspecialchars($baseHref, ENT_QUOTES).'">'.self::REFERRER_META;

        if (preg_match('#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);

            return substr_replace($html, $inject, $at, 0);
        }

        return $inject.$html;
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
