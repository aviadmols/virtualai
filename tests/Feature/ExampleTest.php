<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path sends a visitor INTO the system: a redirect to the merchant panel
     * (Filament bounces a guest on to /merchant/login). It is never a standalone 200 page.
     */
    public function test_the_root_redirects_into_the_merchant_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect((string) config('shopify.merchant_panel_path'));
    }
}
