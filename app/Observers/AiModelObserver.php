<?php

namespace App\Observers;

use App\Models\AiModel;
use App\Models\AiOperation;

/**
 * AiModelObserver — makes the Models-page is_default / is_fallback toggle the single
 * authoring surface for an operation's model choice.
 *
 * The resolver reads ai_operations.default_model / fallback_model (the fast path); the
 * Models page only flipped ai_models.is_default, which the resolver reaches only as a
 * last-resort floor — so a merchant's toggle had no effect. This observer keeps the two
 * in lockstep: on save it enforces EXACTLY ONE active default + ONE active fallback per
 * operation (unseating siblings) and WRITES THROUGH the winner into ai_operations. The
 * resolver is unchanged.
 *
 * GLOBAL (non-tenant) catalog: ai_models / ai_operations are platform-wide, partitioned
 * only by operation_key — no account scope. Sibling writes use withoutEvents() so the
 * observer never re-enters; the write-through targets a DIFFERENT table (no observer) and
 * is equality-guarded, so there is no recursion.
 */
class AiModelObserver
{
    // === CONSTANTS ===
    private const FLAG_DEFAULT = 'is_default';
    private const FLAG_FALLBACK = 'is_fallback';
    private const OP_COLUMN_FOR_FLAG = [
        self::FLAG_DEFAULT => 'default_model',
        self::FLAG_FALLBACK => 'fallback_model',
    ];

    public function saved(AiModel $model): void
    {
        $this->syncFlag($model, self::FLAG_DEFAULT);
        $this->syncFlag($model, self::FLAG_FALLBACK);
    }

    public function deleted(AiModel $model): void
    {
        // A removed winner must not linger in the operation column; recompute from survivors.
        $this->syncFlag($model, self::FLAG_DEFAULT);
        $this->syncFlag($model, self::FLAG_FALLBACK);
    }

    /**
     * If this row is the active winner for a flag: unseat every sibling and push the
     * winner into the matching ai_operations column. Otherwise (toggled off / deactivated
     * / deleted): recompute the column from the surviving active winner — or null it (the
     * resolver then fails loud rather than using a stale model).
     */
    private function syncFlag(AiModel $model, string $flag): void
    {
        $operationKey = $model->operation_key;
        $opColumn = self::OP_COLUMN_FOR_FLAG[$flag];

        if ((bool) $model->{$flag} && (bool) $model->is_active && $model->exists) {
            AiModel::withoutEvents(function () use ($model, $flag, $operationKey): void {
                AiModel::query()
                    ->where('operation_key', $operationKey)
                    ->whereKeyNot($model->getKey())
                    ->where($flag, true)
                    ->update([$flag => false]);
            });

            $this->writeOperationColumn($operationKey, $opColumn, $model->model_id);

            return;
        }

        $survivor = AiModel::query()
            ->where('operation_key', $operationKey)
            ->where($flag, true)
            ->where('is_active', true)
            ->value('model_id');

        $this->writeOperationColumn($operationKey, $opColumn, $survivor);
    }

    /** Idempotent write-through: only touch ai_operations when the value actually changes. */
    private function writeOperationColumn(string $operationKey, string $column, ?string $modelId): void
    {
        $operation = AiOperation::query()->where('operation_key', $operationKey)->first();

        if ($operation === null || $operation->{$column} === $modelId) {
            return;
        }

        $operation->{$column} = $modelId;
        $operation->save();
    }
}
