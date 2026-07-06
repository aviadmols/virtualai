<?php

namespace App\Http\Requests\Widget;

use App\Models\ActivityEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EventRequest — validate a fire-and-forget batch of widget behavioral events
 * (Phase 1d). Records shopper page views + meaningful interactions tied to the
 * EndUser. The batch is capped (MAX_EVENTS); extras are ignored by the controller.
 *
 * Behavioral events are PII: only curated non-secret scalars are ever persisted (the
 * controller curates; the validator only bounds the shape + lengths). Never a raw
 * query string, a full URL, or a payload dump.
 */
final class EventRequest extends FormRequest
{
    // === CONSTANTS ===
    public const FIELD_ANON_TOKEN = 'anon_token';

    public const FIELD_EVENTS = 'events';

    // Per-event keys.
    public const KEY_KIND = 'kind';

    public const KEY_AT = 'at';

    public const KEY_PATH = 'path';

    public const KEY_REFERRER_HOST = 'referrer_host';

    public const KEY_INTERACTION = 'interaction';

    public const KEY_INTERACTION_TYPE = 'type';

    public const KEY_INTERACTION_LABEL = 'label';

    // The two kinds the widget may send. Anything else is dropped (not a 500).
    public const ALLOWED_KINDS = [
        ActivityEvent::KIND_PAGE_VIEW,
        ActivityEvent::KIND_INTERACTION,
    ];

    // Batch cap: at most MAX_EVENTS are processed; extras are ignored (never a 422).
    public const MAX_EVENTS = 20;

    // Field length caps (the controller re-caps on persist; these fail-loose bounds
    // keep an oversized field from ever reaching the recorder).
    public const MAX_PATH = 512;

    public const MAX_REFERRER_HOST = 255;

    public const MAX_INTERACTION_TYPE = 64;

    public const MAX_INTERACTION_LABEL = 120;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately loose: validate only the envelope (a session token + an events
     * array). Per-event shape/kind/length is curated defensively by the controller so
     * ONE malformed event never rejects the whole fire-and-forget batch (the widget
     * is best-effort; extras + bad rows are dropped, never 422'd).
     */
    public function rules(): array
    {
        return [
            self::FIELD_ANON_TOKEN => ['required', 'string', 'min:8', 'max:128'],
            self::FIELD_EVENTS => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            self::FIELD_ANON_TOKEN.'.required' => __('widget_api.validation.anon_token_required'),
        ];
    }
}
