<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CaptureLeadRequest — validate the signup POST (lead capture).
 *
 * full_name + email required; phone optional (a per-site "phone required" policy can be
 * layered later — Q-PHONE). marketing_consent is OPTIONAL and DEFAULTS OFF (GDPR): it is
 * only ever set true from an EXPLICIT truthy value (LeadCapture enforces this too). The
 * anon_token identifies which anonymous end user is registering.
 */
final class CaptureLeadRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_FULL_NAME = 'full_name';
    public const FIELD_EMAIL = 'email';
    public const FIELD_PHONE = 'phone';
    public const FIELD_MARKETING_CONSENT = 'marketing_consent';
    public const FIELD_ANON_TOKEN = 'anon_token';
    public const FIELD_SOURCE = 'source';
    public const FIELD_UTM = 'utm';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            self::FIELD_FULL_NAME => ['required', 'string', 'max:120'],
            self::FIELD_EMAIL => ['required', 'email', 'max:180'],
            self::FIELD_PHONE => ['nullable', 'string', 'max:40'],
            // Marketing consent is optional + default OFF; only an explicit true opts in.
            self::FIELD_MARKETING_CONSENT => ['sometimes', 'boolean'],
            self::FIELD_ANON_TOKEN => ['required', 'string', 'min:8', 'max:128'],
            self::FIELD_SOURCE => ['sometimes', 'nullable', 'string', 'max:80'],
            self::FIELD_UTM => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            self::FIELD_EMAIL.'.required' => __('widget_api.validation.email_required'),
            self::FIELD_EMAIL.'.email' => __('widget_api.validation.email_required'),
            self::FIELD_FULL_NAME.'.required' => __('widget_api.validation.name_required'),
            self::FIELD_ANON_TOKEN.'.required' => __('widget_api.validation.anon_token_required'),
        ];
    }
}
