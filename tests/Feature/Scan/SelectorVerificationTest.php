<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Represent\RepresentationBuilder;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\ScanConstants;
use App\Domain\Scan\Selectors\SelectorDetector;
use App\Domain\Scan\Selectors\SelectorStrategy;
use App\Domain\Scan\Selectors\SelectorVerifier;
use Tests\TestCase;

/**
 * Selector detection + single-element verification + confidence. A selector that
 * resolves to exactly one element is high-confidence; 0 or >1 is flagged. A
 * positional selector is flagged even when it resolves to one. No network.
 */
class SelectorVerificationTest extends TestCase
{
    private function representation()
    {
        $html = file_get_contents(base_path('tests/Fixtures/Scan/shopify_pdp.html'));
        $fetch = new FetchResult($html, 'https://shop.northstead.com/products/merino', ScanConstants::FETCH_VIA_HTTP);

        return (new RepresentationBuilder)->build($fetch);
    }

    public function test_id_selector_resolving_to_one_is_high_confidence(): void
    {
        $rep = $this->representation();
        $verifier = new SelectorVerifier($rep->dom);

        $score = $verifier->score('#add-to-cart');

        $this->assertSame(1, $score['matched_count']);
        $this->assertSame(ScanConstants::STRATEGY_ID, $score['strategy']);
        $this->assertGreaterThan(ScanConstants::REVIEW_FLOOR, $score['confidence']);
        $this->assertFalse($score['needs_review']);
    }

    public function test_selector_resolving_to_zero_is_flagged_low_confidence(): void
    {
        $rep = $this->representation();
        $verifier = new SelectorVerifier($rep->dom);

        $score = $verifier->score('#does-not-exist');

        $this->assertSame(0, $score['matched_count']);
        $this->assertSame(0.0, $score['confidence']);
        $this->assertTrue($score['needs_review']);
    }

    public function test_selector_matching_multiple_elements_is_flagged(): void
    {
        $rep = $this->representation();
        $verifier = new SelectorVerifier($rep->dom);

        // The fixture has TWO submit buttons (form + sticky bar).
        $score = $verifier->score('button[type="submit"]');

        $this->assertGreaterThan(1, $score['matched_count']);
        $this->assertTrue($score['needs_review']);
        $this->assertLessThan(ScanConstants::REVIEW_FLOOR, $score['confidence']);
    }

    public function test_positional_selector_is_flagged_even_when_unique(): void
    {
        $strategy = SelectorStrategy::classify('main > article > div:nth-child(2)');

        $this->assertSame(ScanConstants::STRATEGY_POSITIONAL, $strategy);
        $this->assertTrue(SelectorStrategy::alwaysReview($strategy));
    }

    public function test_detector_picks_single_match_primary_with_fallback_chain(): void
    {
        $rep = $this->representation();
        $detector = new SelectorDetector;

        $detected = $detector->detectAll($rep, [
            ScanConstants::ROLE_ADD_TO_CART => ['#add-to-cart', '.add-to-cart', 'button[type="submit"]'],
            ScanConstants::ROLE_TITLE => ['#nope', 'h1', '.product-title'],
        ]);

        $cart = $detected[ScanConstants::ROLE_ADD_TO_CART];
        // The id resolves to exactly one — it wins over the multi-matching submit.
        $this->assertSame('#add-to-cart', $cart->primary);
        $this->assertSame(1, $cart->matchedCount);
        $this->assertNotEmpty($cart->fallbackChain);

        $title = $detected[ScanConstants::ROLE_TITLE];
        $this->assertSame(1, $title->matchedCount);
        $this->assertFalse($title->needsReview);
    }

    public function test_manual_selector_verdict_reports_resolves_to_one(): void
    {
        $dom = ScanDom::fromHtml(file_get_contents(base_path('tests/Fixtures/Scan/shopify_pdp.html')));
        $verifier = new SelectorVerifier($dom);

        $this->assertTrue($verifier->verdict('#hero-image')['resolves_to_one']);
        $this->assertFalse($verifier->verdict('button[type="submit"]')['resolves_to_one']);
        $this->assertSame(0, $verifier->verdict('.totally-absent')['matched_count']);
    }
}
