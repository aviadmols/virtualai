<?php

namespace Tests\Feature\Scan;

use App\Domain\Scan\Fetch\FetchResult;
use App\Domain\Scan\Map\ImageResolver;
use App\Domain\Scan\Map\VariantMapper;
use App\Domain\Scan\Represent\RepresentationBuilder;
use App\Domain\Scan\ScanConstants;
use Tests\TestCase;

/**
 * Variant axis grouping + control-type detection. A page with a COLOR SWATCH and a
 * SIZE DROPDOWN must yield TWO axes with two distinct control types — the classic
 * "only checked <select>" miss. The lazy/srcset hero resolution is also asserted.
 */
class VariantMappingTest extends TestCase
{
    private function representation()
    {
        $html = file_get_contents(base_path('tests/Fixtures/Scan/shopify_pdp.html'));
        $fetch = new FetchResult($html, 'https://shop.northstead.com/products/merino', ScanConstants::FETCH_VIA_HTTP);

        return (new RepresentationBuilder)->build($fetch);
    }

    public function test_color_swatch_and_size_dropdown_are_two_distinct_axes(): void
    {
        $mapper = new VariantMapper(new ImageResolver);

        $rows = [
            ['axis' => 'Color', 'value' => 'Forest', 'image' => null, 'available' => true],
            ['axis' => 'Color', 'value' => 'Charcoal', 'image' => null, 'available' => true],
            ['axis' => 'Size', 'value' => 'S', 'image' => null, 'available' => true],
            ['axis' => 'Size', 'value' => 'M', 'image' => null, 'available' => true],
        ];

        $result = $mapper->map($rows, $this->representation());

        $this->assertCount(2, $result['axes']);

        $byAxis = collect($result['axes'])->keyBy('axis');
        $this->assertSame('swatch', $byAxis['Color']['control_type']);
        $this->assertSame('dropdown', $byAxis['Size']['control_type']);

        // Flat value rows persisted per (axis,value).
        $this->assertCount(4, $result['values']);
    }

    public function test_image_swatch_variant_is_detected(): void
    {
        $mapper = new VariantMapper(new ImageResolver);

        $rows = [
            ['axis' => 'Color', 'value' => 'Red', 'image' => 'https://cdn.example.com/red.jpg', 'available' => true],
        ];

        $result = $mapper->map($rows, $this->representation());

        $this->assertSame('image_swatch', $result['axes'][0]['control_type']);
        $this->assertSame('https://cdn.example.com/red.jpg', $result['values'][0]['image_url']);
    }

    public function test_lazy_srcset_hero_resolves_to_real_image_not_placeholder(): void
    {
        $resolver = new ImageResolver;

        $resolved = $resolver->resolveBest([
            'src' => 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=',
            'data-src' => 'https://cdn.example.com/hero-600.jpg',
            'data-srcset' => 'https://cdn.example.com/hero-600.jpg 600w, https://cdn.example.com/hero-1200.jpg 1200w',
        ], 'https://shop.example.com/p');

        // The largest srcset candidate wins; the placeholder GIF is rejected.
        $this->assertSame('https://cdn.example.com/hero-1200.jpg', $resolved);
    }

    public function test_relative_image_is_absolutised(): void
    {
        $resolver = new ImageResolver;

        $this->assertSame(
            'https://shop.example.com/img/x.jpg',
            $resolver->resolveUrl('/img/x.jpg', 'https://shop.example.com/products/p'),
        );
    }
}
