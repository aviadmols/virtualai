<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\OperationConfig;
use App\Domain\Ai\StylePresetApplier;
use App\Models\AiOperation;
use App\Models\StylePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StylePresetApplier — the single seam that swaps an operation's user prompt for an approved
 * style preset's prompt, and OperationConfig::withUserPrompt behind it. Proves the override keeps
 * everything else, and that application is FAIL-OPEN (null/unapproved/inactive/mismatched → default).
 */
class StylePresetApplierTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $operationKey = AiOperation::KEY_TRY_ON_GENERATION): OperationConfig
    {
        return new OperationConfig(
            operationKey: $operationKey,
            model: 'model-x',
            fallbackModel: 'model-y',
            systemPrompt: 'SYSTEM GUARDRAILS',
            userPrompt: 'DEFAULT PROMPT',
            imageQuality: 'high',
            aspectRatio: '1:1',
            params: ['seed' => 7],
            creditMultiplier: 2.5,
            promptVersion: 3,
            estimatedCostMicroUsd: 1000,
            inputSchema: null,
        );
    }

    private function applier(): StylePresetApplier
    {
        return app(StylePresetApplier::class);
    }

    public function test_with_user_prompt_overrides_only_the_user_prompt(): void
    {
        $c = $this->config()->withUserPrompt('A NEW LOOK');

        $this->assertSame('A NEW LOOK', $c->userPrompt);
        // Everything else is preserved — especially the system prompt + model + cost.
        $this->assertSame('SYSTEM GUARDRAILS', $c->systemPrompt);
        $this->assertSame('model-x', $c->model);
        $this->assertSame('model-y', $c->fallbackModel);
        $this->assertSame(2.5, $c->creditMultiplier);
        $this->assertSame('1:1', $c->aspectRatio);
    }

    public function test_an_approved_matching_preset_is_applied(): void
    {
        $preset = StylePreset::create([
            'name' => 'vintage', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'A warm vintage look, {{materials}}.',
            'status' => StylePreset::STATUS_APPROVED, 'is_active' => true,
        ]);

        $c = $this->applier()->applyTo($this->config(), $preset->id);

        $this->assertSame('A warm vintage look, {{materials}}.', $c->userPrompt);
    }

    public function test_null_preset_is_a_no_op(): void
    {
        $this->assertSame('DEFAULT PROMPT', $this->applier()->applyTo($this->config(), null)->userPrompt);
    }

    public function test_an_unapproved_or_inactive_preset_falls_open_to_the_default(): void
    {
        $draft = StylePreset::create([
            'name' => 'd', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'X', 'status' => StylePreset::STATUS_DRAFT, 'is_active' => true,
        ]);
        $inactive = StylePreset::create([
            'name' => 'i', 'operation_key' => AiOperation::KEY_TRY_ON_GENERATION,
            'user_prompt' => 'X', 'status' => StylePreset::STATUS_APPROVED, 'is_active' => false,
        ]);

        $this->assertSame('DEFAULT PROMPT', $this->applier()->applyTo($this->config(), $draft->id)->userPrompt);
        $this->assertSame('DEFAULT PROMPT', $this->applier()->applyTo($this->config(), $inactive->id)->userPrompt);
        $this->assertSame('DEFAULT PROMPT', $this->applier()->applyTo($this->config(), 999999)->userPrompt);
    }

    public function test_a_preset_for_a_different_operation_is_never_applied(): void
    {
        // A banner style can never hijack a try-on generation.
        $banner = StylePreset::create([
            'name' => 'b', 'operation_key' => AiOperation::KEY_BANNER_GENERATION,
            'user_prompt' => 'BANNER STYLE', 'status' => StylePreset::STATUS_APPROVED, 'is_active' => true,
        ]);

        $c = $this->applier()->applyTo($this->config(AiOperation::KEY_TRY_ON_GENERATION), $banner->id);

        $this->assertSame('DEFAULT PROMPT', $c->userPrompt);
    }
}
