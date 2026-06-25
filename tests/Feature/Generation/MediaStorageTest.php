<?php

namespace Tests\Feature\Generation;

use App\Domain\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MediaStorage — source + result images land on the (faked) media disk under a
 * tenant/site scoped path, are stored PRIVATE, and are read only via a SHORT-lived
 * signed URL. The persisted ref is an opaque key, never a public URL.
 */
class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES = "\x89PNG\r\n\x1a\nMEDIA";

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trayon.media.disk', 's3');
        config()->set('trayon.media.signed_ttl', 600);
        Storage::fake('s3');
    }

    public function test_stores_source_and_result_under_tenant_scoped_paths(): void
    {
        $media = app(MediaStorage::class);

        $source = $media->storeSource(7, 12, 99, self::BYTES, 'image/png');
        $result = $media->storeResult(7, 12, 99, self::BYTES, 'image/jpeg');

        // Account leads every path; the generation id segments the prefix.
        $this->assertStringStartsWith('accounts/7/sites/12/generations/99/source-', $source->path);
        $this->assertStringStartsWith('accounts/7/sites/12/generations/99/result-', $result->path);
        $this->assertStringEndsWith('.png', $source->path);
        $this->assertStringEndsWith('.jpg', $result->path);

        Storage::disk('s3')->assertExists($source->path);
        Storage::disk('s3')->assertExists($result->path);
    }

    public function test_signed_url_carries_an_expiration_and_is_not_the_raw_path(): void
    {
        $media = app(MediaStorage::class);
        $stored = $media->storeResult(1, 2, 3, self::BYTES, 'image/png');

        $signed = $media->signedUrl($stored->path);

        $this->assertNotNull($signed);
        $this->assertStringContainsString('expiration=', $signed);
        $this->assertNotSame($stored->path, $signed);
    }

    public function test_signed_url_of_null_path_is_null(): void
    {
        $this->assertNull(app(MediaStorage::class)->signedUrl(null));
        $this->assertNull(app(MediaStorage::class)->signedUrl(''));
    }

    public function test_signed_ttl_comes_from_config_not_a_literal(): void
    {
        config()->set('trayon.media.signed_ttl', 1234);
        $this->assertSame(1234, app(MediaStorage::class)->ttlSeconds());
    }

    public function test_delete_removes_the_object(): void
    {
        $media = app(MediaStorage::class);
        $stored = $media->storeSource(1, 1, 1, self::BYTES, 'image/png');
        Storage::disk('s3')->assertExists($stored->path);

        $media->delete($stored->path);
        Storage::disk('s3')->assertMissing($stored->path);
    }
}
