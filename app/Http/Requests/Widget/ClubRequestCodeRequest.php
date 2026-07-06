<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ClubRequestCodeRequest — validate POST /widget/v1/club/request-code.
 *
 * anon_token identifies the anonymous shopper; email is the address the one-time
 * code is sent to (and later verified against). Typed 422 JSON on a bad shape —
 * never HTML/500. The per-email anti-spam throttle is the service's job, not the
 * validator's.
 */
final class ClubRequestCodeRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_ANON_TOKEN = 'anon_token';

    public const FIELD_EMAIL = 'email';

    private const EMAIL_MAX = 180;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            self::FIELD_ANON_TOKEN => ['required', 'string', 'min:8', 'max:128'],
            self::FIELD_EMAIL => ['required', 'email', 'max:'.self::EMAIL_MAX],
        ];
    }

    public function messages(): array
    {
        return [
            self::FIELD_EMAIL.'.required' => __('widget_api.validation.email_required'),
            self::FIELD_EMAIL.'.email' => __('widget_api.validation.email_required'),
            self::FIELD_ANON_TOKEN.'.required' => __('widget_api.validation.anon_token_required'),
        ];
    }
}
