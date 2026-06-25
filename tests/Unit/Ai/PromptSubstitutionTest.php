<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\OperationConfig;
use PHPUnit\Framework\TestCase;

/**
 * Proves prompt templating uses strtr() and NEVER executes code (RCE prevention).
 * Merchant/admin-edited prompt text + placeholder values are DATA, not code: a
 * value carrying Blade-ish or PHP-ish text is rendered LITERALLY, never evaluated.
 */
class PromptSubstitutionTest extends TestCase
{
    private function config(string $userPrompt, ?string $systemPrompt = null): OperationConfig
    {
        return new OperationConfig(
            operationKey: 'try_on_generation',
            model: 'm',
            fallbackModel: null,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            imageQuality: null,
            aspectRatio: null,
            params: [],
            creditMultiplier: null,
            promptVersion: 1,
            estimatedCostMicroUsd: null,
            inputSchema: null,
        );
    }

    public function test_strtr_substitutes_placeholders(): void
    {
        $out = $this->config('Hello {{name}}, your height is {{height}}cm.')
            ->substituteUser(['name' => 'Aviad', 'height' => 180]);

        $this->assertSame('Hello Aviad, your height is 180cm.', $out);
    }

    public function test_blade_directives_in_template_are_not_executed(): void
    {
        // A template author who pasted Blade gets it back LITERALLY (no render).
        $template = 'Product: {{product_name}} @php echo 1+1; @endphp {{ 7 * 7 }}';

        $out = $this->config($template)->substituteUser(['product_name' => 'Shoe']);

        // {{product_name}} was substituted; the Blade-ish bits are untouched text.
        $this->assertSame('Product: Shoe @php echo 1+1; @endphp {{ 7 * 7 }}', $out);
        $this->assertStringNotContainsString('49', $out);   // {{ 7 * 7 }} never evaluated
        $this->assertStringNotContainsString('2', $out);    // @php never executed
    }

    public function test_placeholder_value_carrying_blade_is_rendered_literally(): void
    {
        // The injected VALUE itself is hostile Blade/PHP — it must stay literal.
        $out = $this->config('Note: {{note}}')->substituteUser([
            'note' => '{{ 7*7 }} @php system("rm -rf /") @endphp',
        ]);

        $this->assertSame('Note: {{ 7*7 }} @php system("rm -rf /") @endphp', $out);
        $this->assertStringNotContainsString('49', $out);
    }

    public function test_system_prompt_substitution_also_uses_strtr(): void
    {
        $out = $this->config('user', 'You scan {{product_type}}.')
            ->substituteSystem(['product_type' => 'shoes {{ 7*7 }}']);

        $this->assertSame('You scan shoes {{ 7*7 }}.', $out);
    }

    public function test_missing_placeholder_value_renders_empty(): void
    {
        $out = $this->config('A {{present}} B {{absent}} C')
            ->substituteUser(['present' => 'X', 'absent' => null]);

        $this->assertSame('A X B  C', $out);
    }
}
