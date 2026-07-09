<?php

namespace App\Support;

/**
 * The allow-list of GLOBAL (non-tenant) models — those intentionally NOT
 * scoped by BelongsToAccount.
 *
 * Tenant-safety is a release blocker, so the rule is: every tenant-owned model
 * carries account_id + BelongsToAccount. The ONLY models exempt are the
 * platform-wide control-plane catalogs listed here. The future isolation audit
 * (saas-credits-billing) asserts that the set of un-scoped models equals
 * exactly this list — any other un-scoped model is a leak.
 *
 * These models do not exist yet (they arrive with ai-openrouter / the platform
 * control plane); the FQCNs are registered up front so the audit has a stable
 * contract to check against. Add a class here ONLY when it is a genuinely
 * platform-global catalog, never to silence the audit for a tenant model.
 */
final class GlobalModels
{
    // === CONSTANTS ===
    // Documented allow-list (ARCHITECTURE.md "global, non-tenant models").
    // FQCNs are listed even before the classes exist so the audit contract is stable.
    public const ALLOW_LIST = [
        \App\Models\User::class,             // account owners are isolated by account_id but
                                             // platform super-admins are global; auth lives
                                             // outside the tenant global scope (see User model).
        'App\\Models\\AiModel',              // OpenRouter model catalog (ai-openrouter).
        'App\\Models\\AiOperation',          // per-operation defaults (ai-openrouter).
        'App\\Models\\Prompt',               // global/product_type-scoped prompts (ai-openrouter).
        'App\\Models\\PlatformSetting',      // platform-wide settings (control plane).
        'App\\Models\\PlaygroundRun',        // Super-Admin model-test runs (no tenant; never charges).
        // Storyboard (admin AI pre-production builder) — admin-owned, no tenant, never charges.
        'App\\Models\\StoryboardProject',
        'App\\Models\\StoryboardAsset',
        'App\\Models\\StoryboardFrame',
        'App\\Models\\StoryboardFrameVersion',
        'App\\Models\\StoryboardStepRun',
    ];

    /** True if $class is on the global (non-tenant) allow-list. */
    public static function isGlobal(string $class): bool
    {
        return in_array(ltrim($class, '\\'), self::normalized(), true);
    }

    /** The allow-list with leading backslashes stripped for comparison. */
    public static function normalized(): array
    {
        return array_map(static fn (string $c): string => ltrim($c, '\\'), self::ALLOW_LIST);
    }
}
