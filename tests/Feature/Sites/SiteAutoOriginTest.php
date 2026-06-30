<?php

namespace Tests\Feature\Sites;

use App\Models\Account;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A site auto-allows its OWN domain origin so the widget runs there without a manual
 * allowed-origins step — but only when none are set, so explicit origins are preserved.
 */
class SiteAutoOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_origin_is_derived_from_a_domain_or_url(): void
    {
        $this->assertSame('https://shop.example.com', Site::originFromDomain('https://shop.example.com/products/x'));
        $this->assertSame('https://shop.example.com', Site::originFromDomain('shop.example.com')); // assume https
        $this->assertSame('http://localhost:3000', Site::originFromDomain('http://localhost:3000'));
        $this->assertNull(Site::originFromDomain(''));
        $this->assertNull(Site::originFromDomain(null));
    }

    public function test_a_site_created_with_a_domain_auto_allows_its_origin(): void
    {
        $account = Account::factory()->create();

        $site = Site::factory()->forAccount($account)->create([
            'domain' => 'https://rocksandgold.co.il/',
            'allowed_origins' => [],
        ]);

        $this->assertSame(['https://rocksandgold.co.il'], $site->allowed_origins);
    }

    public function test_explicit_origins_are_preserved(): void
    {
        $account = Account::factory()->create();

        $site = Site::factory()->forAccount($account)->create([
            'domain' => 'https://rocksandgold.co.il/',
            'allowed_origins' => ['https://other.example.com'],
        ]);

        $this->assertSame(['https://other.example.com'], $site->allowed_origins);
    }
}
