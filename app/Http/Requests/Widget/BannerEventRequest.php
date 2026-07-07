<?php

namespace App\Http\Requests\Widget;

use App\Models\BannerEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * BannerEventRequest — the POST /widget/v1/banners/event payload: which banner, what kind
 * (impression|click), the shopper's anon token (for per-session impression de-dupe) + the page
 * path. A bad payload is a typed 422 JSON (the widget's beacon is fire-and-forget and ignores it).
 */
class BannerEventRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_BANNER_ID = 'banner_id';

    public const FIELD_KIND = 'kind';

    public const FIELD_ANON_TOKEN = 'anon_token';

    public const FIELD_PATH = 'path';

    public function authorize(): bool
    {
        return true; // the widget-auth middleware (ResolveWidgetSite) already gated the request
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            self::FIELD_BANNER_ID => ['required', 'integer'],
            self::FIELD_KIND => ['required', Rule::in(BannerEvent::KINDS)],
            self::FIELD_ANON_TOKEN => ['nullable', 'string', 'max:120'],
            self::FIELD_PATH => ['nullable', 'string', 'max:1024'],
        ];
    }
}
