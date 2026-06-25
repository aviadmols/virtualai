<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AddToCartEventRequest — validate the add-to-cart funnel event. The actual cart add is
 * the host platform's job (Phase 7b bridges it); this records the funnel advance only.
 * An optional generation_id ties the event to the try-on the shopper added.
 */
final class AddToCartEventRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_ANON_TOKEN = 'anon_token';
    public const FIELD_GENERATION_ID = 'generation_id';
    public const FIELD_VARIANT_ID = 'variant_id';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            self::FIELD_ANON_TOKEN => ['required', 'string', 'min:8', 'max:128'],
            self::FIELD_GENERATION_ID => ['sometimes', 'nullable', 'integer'],
            self::FIELD_VARIANT_ID => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            self::FIELD_ANON_TOKEN.'.required' => __('widget_api.validation.anon_token_required'),
        ];
    }
}
