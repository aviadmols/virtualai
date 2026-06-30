<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OpenRouter retired the try-on model ids the control plane shipped with —
 * google/gemini-2.5-flash-image-preview AND openai/gpt-image-1 both 404 now. Repoint the
 * try_on_generation operation + its catalog rows at current ids so an EXISTING deploy
 * starts working the moment a real API key is set, with no manual panel edit. The scan
 * models (google/gemini-2.5-flash, openai/gpt-4o-mini) are still valid and untouched.
 */
return new class extends Migration
{
    private const OP = 'try_on_generation';
    private const OLD_DEFAULT = 'google/gemini-2.5-flash-image-preview';
    private const NEW_DEFAULT = 'google/gemini-2.5-flash-image';
    private const OLD_FALLBACK = 'openai/gpt-image-1';
    private const NEW_FALLBACK = 'google/gemini-3.1-flash-image';

    public function up(): void
    {
        // The resolver reads these strings (operation.default_model / fallback_model).
        DB::table('ai_operations')->where('operation_key', self::OP)->update([
            'default_model' => self::NEW_DEFAULT,
            'fallback_model' => self::NEW_FALLBACK,
        ]);

        $this->rename(self::OLD_DEFAULT, self::NEW_DEFAULT, 'Gemini 2.5 Flash Image');
        $this->rename(self::OLD_FALLBACK, self::NEW_FALLBACK, 'Gemini 3.1 Flash Image');
    }

    public function down(): void
    {
        DB::table('ai_operations')->where('operation_key', self::OP)->update([
            'default_model' => self::OLD_DEFAULT,
            'fallback_model' => self::OLD_FALLBACK,
        ]);

        $this->rename(self::NEW_DEFAULT, self::OLD_DEFAULT, 'Gemini 2.5 Flash Image');
        $this->rename(self::NEW_FALLBACK, self::OLD_FALLBACK, 'GPT Image 1');
    }

    /**
     * Rename a catalog row in place (keeping its cost hint). Only when the stale id exists,
     * so it is a no-op on a fresh DB the seeder fills with current ids; if the target id
     * already exists, drop the stale row instead of clashing the unique (op, model_id).
     */
    private function rename(string $from, string $to, string $label): void
    {
        $base = fn () => DB::table('ai_models')->where('operation_key', self::OP);

        if ($base()->where('model_id', $to)->exists()) {
            $base()->where('model_id', $from)->delete();

            return;
        }

        $base()->where('model_id', $from)->update(['model_id' => $to, 'label' => $label]);
    }
};
