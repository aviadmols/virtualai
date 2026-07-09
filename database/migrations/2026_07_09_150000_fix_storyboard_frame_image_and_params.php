<?php

use App\Models\AiModel;
use App\Models\AiOperation;
use Illuminate\Database\Migrations\Migration;

/**
 * Two production fixes for the storyboard pipeline:
 *   1. The frame-image step defaulted to google/gemini-3.1-flash-image, which returns 400 on
 *      OpenRouter — switch it to the proven google/gemini-2.5-flash-image (+ its model row).
 *   2. Numeric params saved through the Pipeline-Settings KeyValue editor were stored as STRINGS
 *      ("0.6"), and a string temperature is a 400 — coerce them back to numbers. (The caller also
 *      coerces at send-time; this cleans the stored data.)
 *
 * The seed migration already ran, so this targeted migration is needed to fix existing rows. Config
 * data only; the down() is a no-op.
 */
return new class extends Migration
{
    private const NUMERIC = [
        'temperature' => 'float',
        'top_p' => 'float',
        'max_tokens' => 'int',
        'seed' => 'int',
        'duration_seconds' => 'int',
    ];

    public function up(): void
    {
        $model = 'google/gemini-2.5-flash-image';

        AiOperation::query()
            ->where('operation_key', AiOperation::KEY_STORYBOARD_FRAME_IMAGE)
            ->update(['default_model' => $model, 'fallback_model' => null]);

        AiModel::query()->where('operation_key', AiOperation::KEY_STORYBOARD_FRAME_IMAGE)->update(['is_default' => false]);

        AiModel::updateOrCreate(
            ['operation_key' => AiOperation::KEY_STORYBOARD_FRAME_IMAGE, 'model_id' => $model],
            [
                'provider' => AiModel::PROVIDER_OPENROUTER,
                'label' => 'Gemini 2.5 Flash Image',
                'is_default' => true,
                'is_active' => true,
                'cost_unit' => AiModel::UNIT_PER_IMAGE,
                'cost_hint_micro_usd' => 40_000,
            ],
        );

        foreach (AiOperation::query()->where('operation_key', 'like', 'storyboard_%')->get() as $op) {
            $params = is_array($op->params) ? $op->params : [];
            $changed = false;

            foreach ($params as $key => $value) {
                if (isset(self::NUMERIC[$key]) && is_numeric($value) && ! is_int($value) && ! is_float($value)) {
                    $params[$key] = self::NUMERIC[$key] === 'int' ? (int) $value : (float) $value;
                    $changed = true;
                }
            }

            if ($changed) {
                $op->update(['params' => $params]);
            }
        }
    }

    public function down(): void
    {
        // Config fix — nothing to roll back.
    }
};
