<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\OperationConfig;
use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\PlatformDirective;
use App\Models\Site;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super-admin Global Rules: a platform-global directive woven into the SYSTEM prompt of every
 * generation of a surface (image_studio | try_on), across ALL sites, with its version folded into
 * the idempotency keys so a rule edit re-generates instead of colliding. Proves the resolver seam
 * and the money-safety key variation; the no-directive path stays byte-identical (proven elsewhere).
 */
class GlobalRulesTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE_RULE = 'Always a pure white seamless background.';

    private const TRYON_RULE = 'Match the shopper lighting exactly.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
    }

    private function site(): Site
    {
        $account = Account::factory()->create();

        return Site::factory()->forAccount($account)->create();
    }

    private function resolve(string $operationKey, Site $site): OperationConfig
    {
        return app(AiOperationResolver::class)->for($operationKey, $site);
    }

    public function test_an_active_directive_is_woven_into_its_surface_and_only_its_surface(): void
    {
        $site = $this->site();
        PlatformDirective::create(['surface' => PlatformDirective::SURFACE_IMAGE_STUDIO, 'rules' => self::IMAGE_RULE, 'version' => 3, 'is_active' => true]);

        // Both image-studio operations carry it, with the directive version.
        foreach ([AiOperation::KEY_PACKSHOT_GENERATION, AiOperation::KEY_ON_MODEL_GENERATION] as $op) {
            $config = $this->resolve($op, $site);
            $this->assertStringContainsString(self::IMAGE_RULE, (string) $config->systemPrompt);
            $this->assertSame(3, $config->directiveVersion);
        }

        // Try-on is a DIFFERENT surface — untouched by the image-studio directive.
        $tryOn = $this->resolve(AiOperation::KEY_TRY_ON_GENERATION, $site);
        $this->assertStringNotContainsString(self::IMAGE_RULE, (string) $tryOn->systemPrompt);
        $this->assertSame(0, $tryOn->directiveVersion);
    }

    public function test_an_inactive_or_empty_directive_is_a_noop(): void
    {
        $site = $this->site();
        $directive = PlatformDirective::create(['surface' => PlatformDirective::SURFACE_TRY_ON, 'rules' => self::TRYON_RULE, 'version' => 2, 'is_active' => false]);

        // Inactive → not applied.
        $config = $this->resolve(AiOperation::KEY_TRY_ON_GENERATION, $site);
        $this->assertStringNotContainsString(self::TRYON_RULE, (string) $config->systemPrompt);
        $this->assertSame(0, $config->directiveVersion);

        // Active but blank rules → still a no-op.
        $directive->update(['is_active' => true, 'rules' => '   ']);
        $this->assertSame(0, $this->resolve(AiOperation::KEY_TRY_ON_GENERATION, $site)->directiveVersion);
    }

    public function test_a_directive_applies_to_every_site(): void
    {
        $siteA = $this->site();
        $siteB = $this->site();
        PlatformDirective::create(['surface' => PlatformDirective::SURFACE_TRY_ON, 'rules' => self::TRYON_RULE, 'version' => 1, 'is_active' => true]);

        foreach ([$siteA, $siteB] as $site) {
            $config = $this->resolve(AiOperation::KEY_TRY_ON_GENERATION, $site);
            $this->assertStringContainsString(self::TRYON_RULE, (string) $config->systemPrompt);
            $this->assertSame(1, $config->directiveVersion);
        }
    }

    public function test_the_image_studio_key_is_byte_identical_at_version_zero_and_varies_when_it_changes(): void
    {
        $extra = ['style_id' => null, 'notes' => '', 'aspect_ratio' => '', 'image_quality' => ''];
        $key = fn (int $dv): string => IdempotencyKey::forProductAsset(
            accountId: 1, siteId: 1, productId: 1, sourceImageHash: 'h',
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION, promptVersion: 1, modelId: 'm',
            modelParams: [], clientRequestId: 'batch', extra: $extra, directiveVersion: $dv,
        );

        // directiveVersion 0 == the same key as omitting the arg (existing keys unchanged).
        $noArg = IdempotencyKey::forProductAsset(
            accountId: 1, siteId: 1, productId: 1, sourceImageHash: 'h',
            operationKey: AiOperation::KEY_PACKSHOT_GENERATION, promptVersion: 1, modelId: 'm',
            modelParams: [], clientRequestId: 'batch', extra: $extra,
        );
        $this->assertSame($noArg, $key(0));
        $this->assertNotSame($key(0), $key(4)); // a rule edit (version bump) re-generates
    }

    public function test_the_try_on_key_is_byte_identical_at_version_zero_and_varies_when_it_changes(): void
    {
        $noArg = IdempotencyKey::forGeneration(1, 1, 1, 1, [], 'crq');
        $this->assertSame($noArg, IdempotencyKey::forGeneration(1, 1, 1, 1, [], 'crq', 0));
        $this->assertNotSame($noArg, IdempotencyKey::forGeneration(1, 1, 1, 1, [], 'crq', 5));
    }
}
