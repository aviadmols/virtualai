<?php

namespace Tests\Feature\Media;

use App\Domain\Media\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Media served from a LOCAL disk (a Railway Volume). Proves publicUrl()/signedUrl() switch to
 * app routes for a local disk, that a public banner is served cacheably WITHOUT a signature,
 * that the public door refuses a private (try-on) path — so it can never leak — and that a
 * private object requires a valid, expiring signature.
 */
final class MediaVolumeServingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 'volume');
        config()->set('trayon.media.signed_ttl', 600);
        Storage::fake('volume');
    }

    private function media(): MediaStorage
    {
        return app(MediaStorage::class);
    }

    public function test_a_public_banner_is_served_cacheably_without_a_signature(): void
    {
        $stored = $this->media()->storeBannerResult(1, 1, 9, 'PNGBYTES', 'image/png');
        $url = $this->media()->publicUrl($stored->path);

        $this->assertStringContainsString('/media/pub/', (string) $url);
        $this->assertStringContainsString('/banners/', (string) $url);

        $res = $this->get($url);
        $res->assertOk();
        $this->assertSame('PNGBYTES', $res->streamedContent());
        $this->assertStringContainsString('max-age=31536000', (string) $res->headers->get('Cache-Control'));
    }

    public function test_the_public_door_refuses_a_private_try_on_path(): void
    {
        // A private generation object (…/generations/…) must NOT be reachable via the banner door.
        $stored = $this->media()->storeResult(1, 1, 5, 'SECRET', 'image/png');

        $this->get('/media/pub/'.$stored->path)->assertNotFound();
    }

    public function test_private_media_needs_a_valid_signature(): void
    {
        $stored = $this->media()->storeResult(1, 1, 5, 'SECRET', 'image/png');
        $signed = $this->media()->signedUrl($stored->path);

        // The signed URL serves the bytes...
        $ok = $this->get($signed);
        $ok->assertOk();
        $this->assertSame('SECRET', $ok->streamedContent());

        // ...but the same route without a valid signature is refused.
        $this->get(route('media.signed', ['path' => $stored->path]))->assertForbidden();
    }
}
