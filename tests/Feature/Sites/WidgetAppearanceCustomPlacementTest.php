<?php

namespace Tests\Feature\Sites;

use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\WidgetAppearance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Custom (visually-picked) button placement. WidgetAppearance::sanitize accepts a valid host
 * anchor selector + position when placement=custom, rejects scriptable/oversized selectors and
 * bad positions, requires a concrete anchor when the placement is custom, and resolve() carries
 * the two custom keys through to the widget. Pure schema logic — no DB.
 */
class WidgetAppearanceCustomPlacementTest extends TestCase
{
    public function test_sanitize_accepts_a_valid_custom_placement(): void
    {
        $clean = WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
            WidgetAppearance::KEY_CUSTOM_ANCHOR => '.product-form__buttons > button[name="add"]',
            WidgetAppearance::KEY_CUSTOM_POSITION => WidgetAppearance::POSITION_BEFORE,
        ]);

        $this->assertSame(WidgetAppearance::PLACEMENT_CUSTOM, $clean[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('.product-form__buttons > button[name="add"]', $clean[WidgetAppearance::KEY_CUSTOM_ANCHOR]);
        $this->assertSame(WidgetAppearance::POSITION_BEFORE, $clean[WidgetAppearance::KEY_CUSTOM_POSITION]);
    }

    public function test_custom_placement_requires_a_non_empty_anchor(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);

        WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
            // no custom_anchor_selector — a custom placement with nothing to anchor to is invalid.
        ]);
    }

    public static function badSelectorProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'angle brackets' => ['div<span'],
            'declaration block' => ['div{color:red}'],
            'css comment' => ['div/*x*/'],
            'backtick' => ['div`x`'],
        ];
    }

    #[DataProvider('badSelectorProvider')]
    public function test_sanitize_rejects_a_scriptable_selector(string $selector): void
    {
        $this->expectException(InvalidSiteSettingsException::class);

        WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
            WidgetAppearance::KEY_CUSTOM_ANCHOR => $selector,
        ]);
    }

    public function test_sanitize_rejects_an_oversized_selector(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);

        WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
            WidgetAppearance::KEY_CUSTOM_ANCHOR => str_repeat('a', WidgetAppearance::CUSTOM_SELECTOR_MAX + 1),
        ]);
    }

    public function test_sanitize_rejects_a_bad_position(): void
    {
        $this->expectException(InvalidSiteSettingsException::class);

        WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
            WidgetAppearance::KEY_CUSTOM_ANCHOR => '#add-to-cart',
            WidgetAppearance::KEY_CUSTOM_POSITION => 'sideways',
        ]);
    }

    public function test_a_legacy_placement_keeps_the_custom_defaults(): void
    {
        // A non-custom placement never requires an anchor; the custom keys stay at their defaults.
        $clean = WidgetAppearance::sanitize([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_AFTER_ATC,
        ]);

        $this->assertSame(WidgetAppearance::PLACEMENT_AFTER_ATC, $clean[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('', $clean[WidgetAppearance::KEY_CUSTOM_ANCHOR]);
        $this->assertSame(WidgetAppearance::POSITION_AFTER, $clean[WidgetAppearance::KEY_CUSTOM_POSITION]);
    }

    public function test_resolve_carries_the_custom_placement_to_the_widget(): void
    {
        $resolved = WidgetAppearance::resolve([
            WidgetAppearance::KEY_PLACEMENT => WidgetAppearance::PLACEMENT_CUSTOM,
            WidgetAppearance::KEY_CUSTOM_ANCHOR => '#buy-box',
            WidgetAppearance::KEY_CUSTOM_POSITION => WidgetAppearance::POSITION_PREPEND,
        ]);

        $this->assertSame(WidgetAppearance::PLACEMENT_CUSTOM, $resolved[WidgetAppearance::KEY_PLACEMENT]);
        $this->assertSame('#buy-box', $resolved[WidgetAppearance::KEY_CUSTOM_ANCHOR]);
        $this->assertSame(WidgetAppearance::POSITION_PREPEND, $resolved[WidgetAppearance::KEY_CUSTOM_POSITION]);
    }
}
