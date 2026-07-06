<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ClubVerifyCodeRequest — validate POST /widget/v1/club/verify-code.
 *
 * anon_token + email must match the request-code call; code is the 6-digit numeric
 * one-time code. The validator only bounds the shape (a wrong/expired code is a
 * typed business outcome from the service, NOT a 422). Typed 422 JSON on a missing
 * field — never HTML/500.
 */
final class ClubVerifyCodeRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_ANON_TOKEN = 'anon_token';

    public const FIELD_EMAIL = 'email';

    public const FIELD_CODE = 'code';

    private const EMAIL_MAX = 180;

    // Bound the code loosely: the service decides correctness. Digits only, sane length.
    private const CODE_MIN = 4;

    private const CODE_MAX = 8;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            self::FIELD_ANON_TOKEN => ['required', 'string', 'min:8', 'max:128'],
            self::FIELD_EMAIL => ['required', 'email', 'max:'.self::EMAIL_MAX],
            self::FIELD_CODE => ['required', 'string', 'min:'.self::CODE_MIN, 'max:'.self::CODE_MAX],
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
