<?php

use App\Models\AiModel;
use App\Models\AiOperation;
use Illuminate\Database\Migrations\Migration;

/**
 * Make google/gemini-3.1-flash-image the ACTIVE try-on model on the existing DB now
 * (the merchant chose it; the resolver reads ai_operations.default_model). Collapses the
 * catalog to exactly one default + one fallback for try_on_generation and points the
 * operation columns at the new ids. withoutEvents so it doesn't race the AiModelObserver.
 */
return new class extends Migration
{
    private const OP = 'try_on_generation';
    private const NEW_DEFAULT = 'google/gemini-3.1-flash-image';
    private const NEW_FALLBACK = 'google/gemini-2.5-flash-image';

    public function up(): void
    {
        AiModel::withoutEvents(function (): void {
            AiModel::query()->where('operation_key', self::OP)
                ->update(['is_default' => false, 'is_fallback' => false]);

            AiModel::query()->where('operation_key', self::OP)->where('model_id', self::NEW_DEFAULT)
                ->update(['is_default' => true, 'is_active' => true]);

            AiModel::query()->where('operation_key', self::OP)->where('model_id', self::NEW_FALLBACK)
                ->update(['is_fallback' => true, 'is_active' => true]);
        });

        AiOperation::query()->where('operation_key', self::OP)->update([
            'default_model' => self::NEW_DEFAULT,
            'fallback_model' => self::NEW_FALLBACK,
        ]);
    }

    public function down(): void
    {
        // Inert: re-running the seeder restores the desired state.
    }
};
