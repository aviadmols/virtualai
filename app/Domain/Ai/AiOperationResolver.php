<?php

namespace App\Domain\Ai;

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

    /**
     * Resolve the full AI-config bag for an operation.
     *
     * @param  string  $operationKey  one of AiOperation::KEYS
     * @param  Site|null  $site  the site the call runs for (drives site/account overrides)
     * @param  string|null  $productType  feeds the product_type prompt leg
     */
    public function for(string $operationKey, ?Site $site = null, ?string $productType = null): OperationConfig
    {
        $operation = AiOperation::query()
            ->where('operation_key', $operationKey)
            ->first();

        if ($operation === null) {
            throw new RuntimeException(sprintf(self::NO_OPERATION_MESSAGE, $operationKey));
        }

        $model = $this->resolveModel($operation, $site);
        $fallback = $this->resolveFallback($operation);
        $prompt = $this->resolvePrompt($operationKey, $site, $productType);

        return new OperationConfig(
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
        );
    }

    /**
     * MODEL: site override -> account override -> operation default -> catalog default.
     * A tenant override is honoured only if it is in the operation's ai_models
     * allow-list (a tenant cannot point at an unlisted/retired model).
     */
    private function resolveModel(AiOperation $operation, ?Site $site): string
    {
        $allowed = $this->allowedModelIds($operation->operation_key);

        $override = $site?->ai_model ?? $this->accountModelOverride($operation, $site);

        if ($override !== null) {
            if (in_array($override, $allowed, true)) {
                return $override;
            }

            // S2: an admin configured an override that isn't allow-listed. Drop it
            // (a tenant can't point at an unlisted/retired model) but tell them so,
            // masked/non-sensitive — a model id + operation key are not secrets.
            $this->warnOverrideDropped($operation, $site, $override);
        }

        // The operation's own default is trusted even if the catalog is sparse
        // (it is platform-set, not tenant-set).
        if ($operation->default_model !== null) {
            return $operation->default_model;
        }

        $catalogDefault = AiModel::query()
            ->forOperation($operation->operation_key)
            ->where('is_default', true)
            ->value('model_id');

        if ($catalogDefault !== null) {
            return $catalogDefault;
        }

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
     * PROMPT: site -> account -> product_type -> global (first match wins).
     * Account/site legs are tenant-aware; product_type/global are platform-global.
     * Within a leg the selection is DETERMINISTIC (S1b): highest version, then
     * lowest id — so two competing same-leg rows always resolve the same way, even
     * before the unique constraint rejects them.
     */
    private function resolvePrompt(string $operationKey, ?Site $site, ?string $productType): Prompt
    {
        if ($site !== null) {
            $sitePrompt = $this->firstDeterministic(
                Prompt::query()->siteScoped((int) $site->account_id, (int) $site->getKey(), $operationKey)
            );

            if ($sitePrompt !== null) {
                return $sitePrompt;
            }

            $accountPrompt = $this->firstDeterministic(
                Prompt::query()->accountScoped((int) $site->account_id, $operationKey)
            );

            if ($accountPrompt !== null) {
                return $accountPrompt;
            }
        }

        if ($productType !== null) {
            $typePrompt = $this->firstDeterministic(
                Prompt::query()->productTypeScoped($productType, $operationKey)
            );

            if ($typePrompt !== null) {
                return $typePrompt;
            }
        }

        $global = $this->firstDeterministic(
            Prompt::query()->globalScoped($operationKey)
        );

        if ($global === null) {
            throw new RuntimeException(sprintf(self::NO_GLOBAL_PROMPT_MESSAGE, $operationKey));
        }

        return $global;
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
