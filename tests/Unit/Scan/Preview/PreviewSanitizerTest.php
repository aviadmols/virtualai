<?php

namespace Tests\Unit\Scan\Preview;

use App\Domain\Scan\Preview\PreviewSanitizer;
use Tests\TestCase;

/**
 * PreviewSanitizer makes a fetched merchant page safe for a sandboxed placement preview:
 * strips scripts/handlers/js-URIs/meta-refresh, KEEPS styles/links (fidelity), swaps the base
 * href to the fetched URL, and injects our picker script. Pure — no network, no DB.
 */
class PreviewSanitizerTest extends TestCase
{
    private const PICKER = '/*PICKER*/window.__trayonPicker=1;';
    private const BASE = 'https://shop.example/p/1';

    private function sanitize(string $html, string $base = self::BASE): string
    {
        return (new PreviewSanitizer())->sanitize($html, $base, self::PICKER);
    }

    public function test_it_strips_scripts_but_keeps_styles_and_links(): void
    {
        $out = $this->sanitize('<html><head><link rel="stylesheet" href="/a.css"><style>.x{color:red}</style><script>alert(1)</script></head><body><p>hi</p></body></html>');

        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<style>.x{color:red}</style>', $out);
        $this->assertStringContainsString('<link rel="stylesheet" href="/a.css">', $out);
    }

    public function test_it_injects_a_reveal_sheet_so_a_preloader_page_is_not_blank(): void
    {
        // The store hides its content behind a preloader + body{opacity:0} that JS normally clears
        // — but we strip JS, so the reveal sheet must force the page visible + hide the preloader.
        $out = $this->sanitize('<html><head><style>body{opacity:0}</style></head><body><div class="preloader">…</div><main>content</main></body></html>');

        $this->assertStringContainsString('opacity:1!important', $out);
        $this->assertStringContainsString('visibility:visible!important', $out);
        $this->assertStringContainsString('[class*="preload" i]', $out);
        // Injected AFTER the store's own styles so the !important reveal wins the cascade.
        $this->assertGreaterThan(strpos($out, 'body{opacity:0}'), strpos($out, 'opacity:1!important'));
    }

    public function test_it_normalises_non_utf8_bytes_to_valid_utf8(): void
    {
        // Windows-1255/latin high bytes (invalid as UTF-8) — real IL storefronts serve these.
        // Left raw they render as mojibake AND break the json_encode Livewire runs each render (500).
        $bad = "\xE7\xE5\xEC\xF6\xE4";
        $out = $this->sanitize('<html><head><meta charset="windows-1255"></head><body><h1>'.$bad.'</h1></body></html>');

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'sanitized output must be valid UTF-8');
        $this->assertNotFalse(json_encode(['html' => $out]), 'sanitized output must survive json_encode');
        // We force the preview charset to UTF-8 (matching our transcode) and drop the merchant's.
        $this->assertStringContainsString('<meta charset="utf-8">', $out);
        $this->assertStringNotContainsString('windows-1255', $out);
    }

    public function test_it_removes_inline_event_handlers(): void
    {
        $out = $this->sanitize('<body><div onclick="steal()" onmouseover=\'x()\'>hi</div><button onload=go>y</button></body>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringNotContainsString('onload', $out);
    }

    public function test_it_neutralizes_javascript_uris(): void
    {
        $out = $this->sanitize('<body><a href="javascript:evil()">x</a></body>');

        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_it_strips_meta_refresh(): void
    {
        $out = $this->sanitize('<head><meta http-equiv="refresh" content="0;url=https://evil.example"></head><body>x</body>');

        $this->assertStringNotContainsString('http-equiv', $out);
        $this->assertStringNotContainsString('evil.example', $out);
    }

    public function test_it_injects_base_href_and_removes_the_merchant_base(): void
    {
        $out = $this->sanitize('<html><head><base href="https://old.example/"></head><body>x</body></html>');

        $this->assertStringContainsString('<base href="'.self::BASE.'">', $out);
        $this->assertStringNotContainsString('https://old.example/', $out);
    }

    public function test_it_injects_the_picker_script_before_body_close(): void
    {
        $out = $this->sanitize('<html><head></head><body><p>hi</p></body></html>');

        $this->assertStringContainsString(self::PICKER, $out);
        $this->assertLessThan(strpos($out, '</body>'), strpos($out, 'window.__trayonPicker=1'));
    }

    public function test_a_fragment_without_head_or_body_still_gets_base_and_picker(): void
    {
        $out = $this->sanitize('<div>only a fragment</div>');

        $this->assertStringContainsString('<base href="'.self::BASE.'">', $out);
        $this->assertStringContainsString(self::PICKER, $out);
        $this->assertStringContainsString('only a fragment', $out);
    }

    public function test_the_base_href_is_html_escaped(): void
    {
        // A URL with a quote/ampersand must never break out of the attribute.
        $out = $this->sanitize('<head></head><body>x</body>', 'https://s.example/?a=1&b="x"');

        $this->assertStringContainsString('&amp;', $out);
        $this->assertStringNotContainsString('b="x"', $out); // the raw quote is escaped
    }
}
