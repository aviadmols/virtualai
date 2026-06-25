<?php

namespace App\Http\Requests\Widget;

use App\Domain\Ai\ImagePayload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StartGenerationRequest — validate the try-on POST before it reaches StartGeneration.
 *
 * The widget sends: the shopper photo (base64 data-URL or string, OR a multipart file),
 * height (+ optional body/age/gender/angle), product_id + variant_id, a stable
 * client_request_id (collapses double-clicks), explicit consent, and the anon_token.
 *
 * Every input is validated here (size/type/range/required) so a bad upload never reaches
 * the worker; failures are typed 422 localized via __(). authorize() is true — the
 * widget-auth middleware already authenticated the site + bound the tenant.
 */
final class StartGenerationRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_PHOTO = 'photo';            // base64 / data-URL string
    public const FIELD_PHOTO_FILE = 'photo_file';  // multipart fallback
    public const FIELD_HEIGHT = 'height';
    public const FIELD_PRODUCT_ID = 'product_id';
    public const FIELD_VARIANT_ID = 'variant_id';
    public const FIELD_CLIENT_REQUEST_ID = 'client_request_id';
    public const FIELD_CONSENT = 'consent';
    public const FIELD_ANON_TOKEN = 'anon_token';
    public const FIELD_EXTRA = 'extra';

    // Height sanity range (cm). A try-on prompt needs a plausible human height.
    public const HEIGHT_MIN_CM = 50;
    public const HEIGHT_MAX_CM = 260;

    // Optional body/age/gender/angle hints the widget may add.
    private const EXTRA_KEYS = ['body', 'age', 'gender', 'angle'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            self::FIELD_PHOTO => ['required_without:'.self::FIELD_PHOTO_FILE, 'nullable', 'string'],
            self::FIELD_PHOTO_FILE => ['required_without:'.self::FIELD_PHOTO, 'nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(ImagePayload::MAX_IMAGE_BYTES / 1024)],
            self::FIELD_HEIGHT => ['required', 'integer', 'between:'.self::HEIGHT_MIN_CM.','.self::HEIGHT_MAX_CM],
            self::FIELD_PRODUCT_ID => ['required', 'integer'],
            self::FIELD_VARIANT_ID => ['required', 'integer'],
            self::FIELD_CLIENT_REQUEST_ID => ['required', 'string', 'max:128'],
            self::FIELD_CONSENT => ['accepted'], // must be true/1/"yes"/"on" — consent is mandatory
            self::FIELD_ANON_TOKEN => ['required', 'string', 'min:8', 'max:128'],
            self::FIELD_EXTRA => ['sometimes', 'array'],
            self::FIELD_EXTRA.'.body' => ['sometimes', 'nullable', 'string', 'max:40'],
            self::FIELD_EXTRA.'.age' => ['sometimes', 'nullable', 'string', 'max:20'],
            self::FIELD_EXTRA.'.gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            self::FIELD_EXTRA.'.angle' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            self::FIELD_HEIGHT.'.between' => __('widget_api.validation.height_range', ['min' => self::HEIGHT_MIN_CM, 'max' => self::HEIGHT_MAX_CM]),
            self::FIELD_CONSENT.'.accepted' => __('widget_api.validation.consent_required'),
            self::FIELD_ANON_TOKEN.'.required' => __('widget_api.validation.anon_token_required'),
            self::FIELD_CLIENT_REQUEST_ID.'.required' => __('widget_api.validation.client_request_id_required'),
            self::FIELD_PRODUCT_ID.'.required' => __('widget_api.validation.product_required'),
            self::FIELD_VARIANT_ID.'.required' => __('widget_api.validation.variant_required'),
            self::FIELD_PHOTO_FILE.'.mimetypes' => __('widget_api.validation.photo_mime'),
            self::FIELD_PHOTO_FILE.'.max' => __('widget_api.validation.photo_size'),
        ];
    }

    /** Only the whitelisted extra-attr keys are carried forward (never arbitrary input). */
    public function extraAttrs(): array
    {
        $extra = (array) $this->input(self::FIELD_EXTRA, []);

        return array_filter(
            array_intersect_key($extra, array_flip(self::EXTRA_KEYS)),
            static fn ($value) => $value !== null && $value !== '',
        );
    }
}
