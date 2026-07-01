<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrective data fix for the broken try-on catalog.
 *
 * The catalog carried a FABRICATED BytePlus model id, 'Dola-Seedream-5.0-lite', which the
 * admin set as the try-on default — so every generation 404'd ("model does not exist or you
 * do not have access"). A second, VALID Seedream id also 404'd, which means the BytePlus
 * account/region has no Seedream access YET. So this migration does NOT push a BytePlus model
 * as the default; it restores the known-good Gemini (OpenRouter) default and keeps Seedream
 * Lite in the catalog, correctly labelled but INACTIVE, as an option to enable once access is
 * confirmed. The real Seedream 5.0 LITE id is 'seedream-5-0-260128' (the fabricated one is
 * dropped). Raw DB writes (no AiModelObserver) so deleting the old default cannot transiently
 * null ai_operations.default_model. Idempotent + a no-op once the bogus row is gone.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const OPERATION = 'try_on_generation';
    private const BOGUS_ID = 'Dola-Seedream-5.0-lite';
    private const REAL_SEEDREAM_ID = 'seedream-5-0-260128';
    private const REAL_SEEDREAM_LABEL = 'Seedream 5.0 Lite (BytePlus)';
    private const SEEDREAM_PRICE_MICRO_USD = 35_000; // $0.035/image — published rate

    private const GEMINI_DEFAULT = 'google/gemini-3.1-flash-image';
    private const GEMINI_FALLBACK = 'google/gemini-2.5-flash-image';

    private const PROVIDER_BYTEPLUS = 'byteplus';
    private const UNIT_PER_IMAGE = 'per_image';

    public function up(): void
    {
        $bogus = DB::table('ai_models')
            ->where('operation_key', self::OPERATION)
            ->where('model_id', self::BOGUS_ID)
            ->first();

        if ($bogus === null) {
            return; // already corrected
        }

        // 1. Correct the real Seedream Lite catalog row — kept as an INACTIVE, non-default
        //    option (BytePlus access unconfirmed). Insert it if the fabricated row was the
        //    only Seedream entry.
        $seedream = DB::table('ai_models')
            ->where('operation_key', self::OPERATION)
            ->where('model_id', self::REAL_SEEDREAM_ID)
            ->first();

        $seedreamAttrs = [
            'provider' => self::PROVIDER_BYTEPLUS,
            'label' => self::REAL_SEEDREAM_LABEL,
            'cost_unit' => self::UNIT_PER_IMAGE,
            'is_active' => false,
            'is_default' => false,
            'is_fallback' => false,
            'updated_at' => now(),
        ];

        if ($seedream === null) {
            DB::table('ai_models')->insert($seedreamAttrs + [
                'operation_key' => self::OPERATION,
                'model_id' => self::REAL_SEEDREAM_ID,
                'cost_hint_micro_usd' => self::SEEDREAM_PRICE_MICRO_USD,
                'created_at' => now(),
            ]);
        } else {
            if ((int) ($seedream->cost_hint_micro_usd ?? 0) <= 0) {
                $seedreamAttrs['cost_hint_micro_usd'] = self::SEEDREAM_PRICE_MICRO_USD;
            }

            DB::table('ai_models')->where('id', $seedream->id)->update($seedreamAttrs);
        }

        // 2. Repoint the operation off the fabricated id onto the known-good Gemini pair.
        DB::table('ai_operations')
            ->where('operation_key', self::OPERATION)
            ->where('default_model', self::BOGUS_ID)
            ->update(['default_model' => self::GEMINI_DEFAULT]);

        DB::table('ai_operations')
            ->where('operation_key', self::OPERATION)
            ->where('fallback_model', self::BOGUS_ID)
            ->update(['fallback_model' => self::GEMINI_FALLBACK]);

        // 3. Make the Gemini default the sole active default so the Models page agrees with
        //    the operation column (raw writes bypass the observer that normally syncs them).
        DB::table('ai_models')
            ->where('operation_key', self::OPERATION)
            ->where('model_id', '!=', self::GEMINI_DEFAULT)
            ->update(['is_default' => false]);

        DB::table('ai_models')
            ->where('operation_key', self::OPERATION)
            ->where('model_id', self::GEMINI_DEFAULT)
            ->update(['is_active' => true, 'is_default' => true, 'updated_at' => now()]);

        // 4. Delete the fabricated row (it never existed upstream).
        DB::table('ai_models')->where('id', $bogus->id)->delete();
    }

    public function down(): void
    {
        // The bogus id never existed on BytePlus; reversing would only re-break generation.
    }
};
