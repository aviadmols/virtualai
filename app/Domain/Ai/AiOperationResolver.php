<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Preview\OperationPreview;
use App\Domain\Ai\Preview\ResolutionStep;
use App\Domain\Ai\Preview\ResolutionTrace;
use App\Domain\Ai\Preview\ResolvedOperation;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Models\AiModel;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AiOperationResolver — the SINGLE source of AI config.
 *
 * Every caller asks for($operationKey, $site, $productType) and gets an immutable
 * OperationConfig bag {model, fallback, system_prompt, user_prompt, quality,
 * aspect_ratio, params, credit_multiplier, ...}. Nothing else in the codebase
 * decides a model or a prompt. Super-Admin changes behaviour from the DB.
 *
 * Resolution:
 *  - MODEL:   site override -> account override -> operation default
 *             -> ai_models is_default flag (the floor). A site/account override
 *             must be in the ai_models allow-list for the operation, else ignored.
 *  - FALLBACK: operation.fallback_model -> ai_models is_fallback flag.
 *  - PROMPT:  site -> account -> product_type -> global (first match wins).
 *             global ALWAYS exists; a missing global is a loud seeding failure,
 *             never a silent prompt-less call.
 *
 * The account/site prompt legs are tenant-aware (explicit account_id constraint
 * via the Prompt query scopes) — no cross-account read. The product_type/global
 * legs are platform-global (account_id NULL).
 */
final class AiOperationResolver
{
    // === CONSTANTS ===
    private const NO_OPERATION_MESSAGE = 'Unknown AI operation "%s": no ai_operations row. The control plane must be seeded.';
    private const NO_GLOBAL_PROMPT_MESSAGE = 'No global prompt for operation "%s": the global prompt is the guaranteed floor and must be seeded (scope=global).';
    private const NO_MODEL_MESSAGE = 'No model resolved for operation "%s": neither ai_operations.default_model nor an ai_models is_default row exists.';

    // Warned when a configured per-site model override is dropped because it is
    // not in the operation's ai_models allow-list (S2). Non-sensitive: a model id
    // and an operation key are not secrets.
    private const OVERRIDE_DROPPED_MESSAGE = 'ai.resolver.site_model_override_ignored';

    // Deterministic in-leg ordering (S1b): the highest version wins, ties broken
    // by the lowest stable id, so a leg with two competing rows always resolves
    // to the same defined row even before the unique constraint is in place.
    private const PROMPT_ORDER_COLUMN = 'version';
    private const PROMPT_TIEBREAK_COLUMN = 'id';

    // Trace kinds (for the read-only preview): which decision a ResolutionTrace
    // describes. Non-sensitive descriptors only — no secrets ever enter a trace.
    private const TRACE_KIND_MODEL = 'model';
    private const TRACE_KIND_PROMPT = 'prompt';

    // Model-resolution trace levels (mirror resolveModel's precedence).
    private const MODEL_LEVEL_SITE_OVERRIDE = 'site_override';
    private const MODEL_LEVEL_ACCOUNT_OVERRIDE = 'account_override';
    private const MODEL_LEVEL_OPERATION_DEFAULT = 'operation_default';
    private const MODEL_LEVEL_CATALOG_DEFAULT = 'catalog_default';

    /**
     * Resolve the full AI-config bag for an operation.
     *
     * @param  string  $operationKey  one of AiOperation::KEYS
     * @param  Site|null  $site  the site the call runs for (drives site/account overrides)
     * @param  string|null  $productType  feeds the product_type prompt leg
     */
    public function for(string $operationKey, ?Site $site = null, ?string $productType = null): OperationConfig
    {
        return $this->resolveInternal($operationKey, $site, $productType)->config;
    }

    /**
     * READ-ONLY preview: "which prompt + which model wins, and why" — WITHOUT
     * running a generation. Makes NO OpenRouter HTTP call and writes NOTHING.
     *
     * Reuses the EXACT SAME resolution precedence as for() (the shared
     * resolveInternal() core), so the winner it reports is byte-for-byte the
     * winner the real pipeline would pick. It additionally surfaces the
     * per-level resolution traces for the prompts editor (Phase 8c).
     *
     * @param  string  $operationKey  one of AiOperation::KEYS
     * @param  Site|null  $site  the site the call would run for (drives site/account overrides)
     * @param  string|null  $productType  feeds the product_type prompt leg
     */
    public function preview(string $operationKey, ?Site $site = null, ?string $productType = null): OperationPreview
    {
        $resolved = $this->resolveInternal($operationKey, $site, $productType);

        return OperationPreview::fromConfig(
            config: $resolved->config,
            siteId: $site?->getKey(),
            accountId: $site?->account_id !== null ? (int) $site->account_id : null,
            productType: $productType,
            winningPromptId: $resolved->winningPrompt->getKey(),
            winningPromptLevel: $resolved->winningPrompt->scope,
            modelTrace: $resolved->modelTrace,
            promptTrace: $resolved->promptTrace,
        );
    }

    /**
     * The SINGLE shared resolution core. Both for() and preview() call this, so
     * they can never drift: the winning model + prompt are decided here exactly
     * once. Building the traces is side-effect-free (only SELECTs) and identical
     * whether or not the caller consumes them — for() simply ignores them.
     */
    private function resolveInternal(string $operationKey, ?Site $site, ?string $productType): ResolvedOperation
    {
        $operation = AiOperation::query()
            ->where('operation_key', $operationKey)
            ->first();

        if ($operation === null) {
            throw new RuntimeException(sprintf(self::NO_OPERATION_MESSAGE, $operationKey));
        }

        $modelTrace = [];
        $model = $this->resolveModel($operation, $site, $modelTrace);
        $fallback = $this->resolveFallback($operation);

        $promptTrace = [];
        $prompt = $this->resolvePrompt($operationKey, $site, $productType, $promptTrace);

        $config = new OperationConfig(
            operationKey: $operationKey,
            model: $model,
            fallbackModel: $fallback,
            systemPrompt: $prompt->system_prompt,
            userPrompt: $prompt->user_prompt,
            imageQuality: $operation->image_quality,
            aspectRatio: $operation->aspect_ratio,
            params: $operation->params ?? [],
            creditMultiplier: $operation->credit_multiplier !== null
                ? (float) $operation->credit_multiplier
                : null,
            promptVersion: (int) $prompt->version,
            estimatedCostMicroUsd: $operation->estimated_cost_micro_usd,
            inputSchema: $operation->input_schema,
            provider: $this->providerForModel($operationKey, $model),
        );

        return new ResolvedOperation(
            config: $config,
            winningPrompt: $prompt,
            modelTrace: new ResolutionTrace(self::TRACE_KIND_MODEL, $modelTrace),
            promptTrace: new ResolutionTrace(self::TRACE_KIND_PROMPT, $promptTrace),
        );
    }

    /**
     * MODEL: site override -> account override -> operation default -> catalog default.
     * A tenant override is honoured only if it is in the operation's ai_models
     * allow-list (a tenant cannot point at an unlisted/retired model).
     *
     * The winner-selection logic is unchanged from the original resolve path;
     * the &$trace out-parameter merely records each level considered for the
     * read-only preview. for() ignores the trace; preview() surfaces it.
     *
     * @param  list<ResolutionStep>  $trace  out: the model-resolution walk
     */
    private function resolveModel(AiOperation $operation, ?Site $site, array &$trace = []): string
    {
        $allowed = $this->allowedModelIds($operation->operation_key);

        $siteOverride = $site?->ai_model;
        $accountOverride = $this->accountModelOverride($operation, $site);
        $override = $siteOverride ?? $accountOverride;
        $overrideLevel = $siteOverride !== null
            ? self::MODEL_LEVEL_SITE_OVERRIDE
            : self::MODEL_LEVEL_ACCOUNT_OVERRIDE;

        if ($override !== null) {
            if (in_array($override, $allowed, true)) {
                $trace[] = new ResolutionStep(
                    level: $overrideLevel,
                    outcome: ResolutionStep::OUTCOME_WON,
                    detail: 'Tenant model override is allow-listed for this operation; it wins.',
                    considered: ['requested_model' => $override, 'allow_listed' => true],
                    winningId: $override,
                );

                return $override;
            }

            // S2: an admin configured an override that isn't allow-listed. Drop it
            // (a tenant can't point at an unlisted/retired model) but tell them so,
            // masked/non-sensitive — a model id + operation key are not secrets.
            $this->warnOverrideDropped($operation, $site, $override);
            $trace[] = new ResolutionStep(
                level: $overrideLevel,
                outcome: ResolutionStep::OUTCOME_NO_MATCH,
                detail: 'Tenant model override is NOT in the ai_models allow-list; dropped.',
                considered: ['requested_model' => $override, 'allow_listed' => false],
            );
        } else {
            $trace[] = new ResolutionStep(
                level: $overrideLevel,
                outcome: ResolutionStep::OUTCOME_NO_MATCH,
                detail: 'No tenant model override configured.',
                considered: ['requested_model' => null],
            );
        }

        // The operation's own default is trusted even if the catalog is sparse
        // (it is platform-set, not tenant-set).
        if ($operation->default_model !== null) {
            $trace[] = new ResolutionStep(
                level: self::MODEL_LEVEL_OPERATION_DEFAULT,
                outcome: ResolutionStep::OUTCOME_WON,
                detail: 'ai_operations.default_model supplied the model.',
                considered: ['default_model' => $operation->default_model],
                winningId: $operation->default_model,
            );

            return $operation->default_model;
        }

        $trace[] = new ResolutionStep(
            level: self::MODEL_LEVEL_OPERATION_DEFAULT,
            outcome: ResolutionStep::OUTCOME_NO_MATCH,
            detail: 'ai_operations.default_model is not set.',
        );

        $catalogDefault = AiModel::query()
            ->forOperation($operation->operation_key)
            ->where('is_default', true)
            ->value('model_id');

        if ($catalogDefault !== null) {
            $trace[] = new ResolutionStep(
                level: self::MODEL_LEVEL_CATALOG_DEFAULT,
                outcome: ResolutionStep::OUTCOME_WON,
                detail: 'ai_models is_default row supplied the model (catalog floor).',
                considered: ['catalog_default' => $catalogDefault],
                winningId: $catalogDefault,
            );

            return $catalogDefault;
        }

        $trace[] = new ResolutionStep(
            level: self::MODEL_LEVEL_CATALOG_DEFAULT,
            outcome: ResolutionStep::OUTCOME_NO_MATCH,
            detail: 'No ai_models is_default row exists for this operation.',
        );

        throw new RuntimeException(sprintf(self::NO_MODEL_MESSAGE, $operation->operation_key));
    }

    /** FALLBACK: operation.fallback_model -> ai_models is_fallback flag. */
    private function resolveFallback(AiOperation $operation): ?string
    {
        if ($operation->fallback_model !== null) {
            return $operation->fallback_model;
        }

        return AiModel::query()
            ->forOperation($operation->operation_key)
            ->where('is_fallback', true)
            ->value('model_id');
    }

    /**
     * Account-level model override. Phase 2's Site is the only override surface
     * that exists today; an account-wide model column can be added later without
     * touching call sites (this is the seam). Returns null until then.
     */
    private function accountModelOverride(AiOperation $operation, ?Site $site): ?string
    {
        return null;
    }

    /** Warn (masked/non-sensitive) that a configured site model override was ignored. */
    private function warnOverrideDropped(AiOperation $operation, ?Site $site, string $override): void
    {
        Log::warning(self::OVERRIDE_DROPPED_MESSAGE, [
            'operation' => $operation->operation_key,
            'site_id' => $site?->getKey(),
            'requested_model' => $override,
            'used_model' => $operation->default_model,
            'reason' => 'not in ai_models allow-list',
        ]);
    }

    /** The allow-list of model ids for an operation (catalog ids + operation defaults). */
    private function allowedModelIds(string $operationKey): array
    {
        $ids = AiModel::query()
            ->forOperation($operationKey)
            ->pluck('model_id')
            ->all();

        return $ids;
    }

    /**
     * The upstream provider for the winning model id, read from the catalog. Defaults to
     * OpenRouter when the id isn't catalogued (e.g. an operation default not in ai_models)
     * — preserving behaviour for the OpenRouter-only case.
     */
    private function providerForModel(string $operationKey, string $modelId): string
    {
        $provider = AiModel::query()
            ->forOperation($operationKey)
            ->where('model_id', $modelId)
            ->value('provider');

        return is_string($provider) && $provider !== ''
            ? $provider
            : ImageGenerationProvider::PROVIDER_OPENROUTER;
    }

    /**
     * PROMPT: site -> account -> product_type -> global (first match wins).
     * Account/site legs are tenant-aware; product_type/global are platform-global.
     * Within a leg the selection is DETERMINISTIC (S1b): highest version, then
     * lowest id — so two competing same-leg rows always resolve the same way, even
     * before the unique constraint rejects them.
     */
    private function resolvePrompt(string $operationKey, ?Site $site, ?string $productType, array &$trace = []): Prompt
    {
        $winner = null;

        if ($site !== null) {
            $sitePrompt = $this->firstDeterministic(
                Prompt::query()->siteScoped((int) $site->account_id, (int) $site->getKey(), $operationKey)
            );
            $this->recordPromptLeg($trace, Prompt::SCOPE_SITE, $sitePrompt, $winner, [
                'account_id' => (int) $site->account_id,
                'site_id' => (int) $site->getKey(),
            ]);
            $winner ??= $sitePrompt;

            $accountPrompt = $winner !== null ? null : $this->firstDeterministic(
                Prompt::query()->accountScoped((int) $site->account_id, $operationKey)
            );
            $this->recordPromptLeg($trace, Prompt::SCOPE_ACCOUNT, $accountPrompt, $winner, [
                'account_id' => (int) $site->account_id,
            ]);
            $winner ??= $accountPrompt;
        } else {
            $this->recordSkippedLeg($trace, Prompt::SCOPE_SITE, 'No site in scope; site prompt leg skipped.');
            $this->recordSkippedLeg($trace, Prompt::SCOPE_ACCOUNT, 'No site in scope; account prompt leg skipped.');
        }

        if ($productType !== null) {
            $typePrompt = $winner !== null ? null : $this->firstDeterministic(
                Prompt::query()->productTypeScoped($productType, $operationKey)
            );
            $this->recordPromptLeg($trace, Prompt::SCOPE_PRODUCT_TYPE, $typePrompt, $winner, [
                'product_type' => $productType,
            ]);
            $winner ??= $typePrompt;
        } else {
            $this->recordSkippedLeg($trace, Prompt::SCOPE_PRODUCT_TYPE, 'No product type supplied; product_type prompt leg skipped.');
        }

        $global = $winner !== null ? null : $this->firstDeterministic(
            Prompt::query()->globalScoped($operationKey)
        );
        $this->recordPromptLeg($trace, Prompt::SCOPE_GLOBAL, $global, $winner, [], isFloor: true);
        $winner ??= $global;

        if ($winner === null) {
            throw new RuntimeException(sprintf(self::NO_GLOBAL_PROMPT_MESSAGE, $operationKey));
        }

        return $winner;
    }

    /**
     * Append one prompt-leg step to the trace. If a winner is already chosen this
     * leg was NOT_REACHED (the real resolver short-circuited before it); else the
     * row (if any) WON this leg, otherwise NO_MATCH. Marking already-won legs as
     * NOT_REACHED keeps the trace faithful: the real resolver never queries them.
     *
     * @param  list<ResolutionStep>  $trace
     * @param  array<string,mixed>  $considered
     */
    private function recordPromptLeg(array &$trace, string $level, ?Prompt $found, ?Prompt $existingWinner, array $considered, bool $isFloor = false): void
    {
        if ($existingWinner !== null) {
            $trace[] = new ResolutionStep(
                level: $level,
                outcome: ResolutionStep::OUTCOME_NOT_REACHED,
                detail: 'A more specific leg already won; this leg was never queried.',
                considered: $considered,
            );

            return;
        }

        if ($found !== null) {
            $trace[] = new ResolutionStep(
                level: $level,
                outcome: ResolutionStep::OUTCOME_WON,
                detail: $isFloor
                    ? 'The global prompt (the guaranteed floor) supplied the winner.'
                    : sprintf('A %s-scoped prompt matched and won.', $level),
                considered: $considered + ['prompt_version' => (int) $found->version],
                winningId: $found->getKey(),
            );

            return;
        }

        $trace[] = new ResolutionStep(
            level: $level,
            outcome: ResolutionStep::OUTCOME_NO_MATCH,
            detail: $isFloor
                ? 'No global prompt exists for this operation (the loud-failure floor).'
                : sprintf('No active %s-scoped prompt for this operation.', $level),
            considered: $considered,
        );
    }

    /**
     * Append a SKIPPED step for a leg whose input was absent (no site / no
     * product type), so the trace shows the leg was deliberately not considered.
     *
     * @param  list<ResolutionStep>  $trace
     */
    private function recordSkippedLeg(array &$trace, string $level, string $detail): void
    {
        $trace[] = new ResolutionStep(
            level: $level,
            outcome: ResolutionStep::OUTCOME_SKIPPED,
            detail: $detail,
        );
    }

    /**
     * Apply the explicit in-leg ordering then take the first row. Ordering is
     * deterministic so a leg with competing rows always resolves to one defined
     * row (the highest version; ties broken by the lowest id).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Prompt>  $query
     */
    private function firstDeterministic($query): ?Prompt
    {
        return $query
            ->orderByDesc(self::PROMPT_ORDER_COLUMN)
            ->orderBy(self::PROMPT_TIEBREAK_COLUMN)
            ->first();
    }
}
