<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\KlingCatalog;
use App\Domain\Ai\KlingJwt;
use PHPUnit\Framework\TestCase;

/**
 * The Kling auth token: an HS256 JWT signed with the SECRET key and issued for the ACCESS key
 * (Kling has no static bearer key). Plus the catalog's endpoint routing, which decides whether a
 * model id runs on the image endpoint or the dedicated virtual-try-on one.
 */
class KlingJwtTest extends TestCase
{
    private const ACCESS_KEY = 'ak-123';

    private const SECRET_KEY = 'sk-456';

    private const NOW = 1_800_000_000;

    public function test_the_token_is_a_verifiable_hs256_jwt_with_klings_claims(): void
    {
        $token = KlingJwt::token(self::ACCESS_KEY, self::SECRET_KEY, self::NOW);

        [$header, $payload, $signature] = explode('.', $token);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $this->decode($header));

        // iss = the access key; exp = +30 min; nbf backdated 5s to absorb clock skew.
        $claims = $this->decode($payload);
        $this->assertSame(self::ACCESS_KEY, $claims['iss']);
        $this->assertSame(self::NOW + 1800, $claims['exp']);
        $this->assertSame(self::NOW - 5, $claims['nbf']);

        // The signature verifies against the SECRET key — and only against it.
        $expected = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, self::SECRET_KEY, true));
        $this->assertSame($expected, $signature);
        $this->assertNotSame(
            $token,
            KlingJwt::token(self::ACCESS_KEY, 'a-different-secret', self::NOW),
        );

        // Base64URL: no padding, no '+' or '/' (a raw base64 signature would break the header).
        $this->assertStringNotContainsString('=', $token);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
    }

    public function test_a_missing_credential_yields_no_token(): void
    {
        $this->assertSame('', KlingJwt::token('', self::SECRET_KEY));
        $this->assertSame('', KlingJwt::token(self::ACCESS_KEY, ''));
    }

    public function test_the_catalog_routes_try_on_models_to_their_own_endpoint(): void
    {
        $this->assertTrue(KlingCatalog::isTryOn('kolors-virtual-try-on-v1-5'));
        $this->assertFalse(KlingCatalog::isTryOn('kling-v2-1'));

        $this->assertSame(KlingCatalog::PATH_TRY_ON, KlingCatalog::imagePath('kolors-virtual-try-on-v1'));
        $this->assertSame(KlingCatalog::PATH_IMAGE, KlingCatalog::imagePath('kling-v2-1'));

        // Video: an input frame means image-to-video, otherwise text-to-video.
        $this->assertSame(KlingCatalog::PATH_IMAGE_TO_VIDEO, KlingCatalog::videoPath(true));
        $this->assertSame(KlingCatalog::PATH_TEXT_TO_VIDEO, KlingCatalog::videoPath(false));
    }

    /** @return array<string,mixed> */
    private function decode(string $segment): array
    {
        return json_decode(base64_decode(strtr($segment, '-_', '+/')), true);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
