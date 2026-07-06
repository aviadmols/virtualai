<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActivityEvent — one immutable row of the human-facing Timeline. Account-scoped
 * (BelongsToAccount). Append-only: created_at only, no updated_at.
 *
 * The full timeline UI is Phase 6; Phase 5a writes the ledger/gate traces. Writes
 * go through ActivityRecorder, which swallows its own exceptions so a failed trace
 * never blocks the money path.
 */
class ActivityEvent extends Model
{
    use BelongsToAccount;

    // === CONSTANTS ===
    public $timestamps = false;

    // Actors.
    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_MERCHANT = 'merchant';

    public const ACTOR_END_USER = 'end_user';

    public const ACTOR_WEBHOOK = 'webhook';

    // The typed kinds Phase 5a emits.
    public const KIND_OPENING_GRANT = 'opening_grant';

    public const KIND_CREDIT_GRANT = 'credit_grant';

    public const KIND_CREDIT_CHARGED = 'credit_charged';

    public const KIND_CREDIT_REFUNDED = 'credit_refunded';

    public const KIND_CREDIT_ADJUSTED = 'credit_adjusted';

    public const KIND_CREDIT_RESERVATION_RELEASED = 'credit_reservation_released';

    public const KIND_CREDIT_GATE_BLOCKED = 'credit_gate_blocked';

    public const KIND_LEAD_GATE_BLOCKED = 'lead_gate_blocked';

    public const KIND_LEAD_REGISTERED = 'lead_registered';

    public const KIND_LEAD_ADDED_TO_CART = 'lead_added_to_cart';   // widget add-to-cart funnel event

    // Widget behavioral events (Phase 1d). Fire-and-forget page views + meaningful
    // interactions the shopper made, tied to the EndUser. Curated non-secret details only.
    public const KIND_PAGE_VIEW = 'page_view';

    public const KIND_INTERACTION = 'interaction';

    // Platform control-plane account actions (super-admin).
    public const KIND_ACCOUNT_SUSPENDED = 'account_suspended';

    public const KIND_ACCOUNT_RESTORED = 'account_restored';

    // Audited super-admin "Open shop workspace" drill-in: a super-admin entered a
    // specific shop's merchant workspace. Explicit + logged (canAccessTenant permits it).
    public const KIND_PLATFORM_SHOP_DRILL_IN = 'platform_shop_drill_in';

    // Site control-plane actions (merchant / platform admin).
    public const KIND_SITE_KEY_REGENERATED = 'site_key_regenerated';

    public const KIND_SITE_SETTINGS_UPDATED = 'site_settings_updated';

    // Phase 6 generation-pipeline taxonomy. Each step of the money path leaves a trace.
    public const KIND_GENERATION_REQUESTED = 'generation_requested';   // StartGeneration created the row

    public const KIND_GENERATION_RESERVED = 'generation_reserved';     // credit reserved, before the model call

    public const KIND_GENERATION_PROCESSING = 'generation_processing'; // model call in flight

    public const KIND_GENERATION_SUCCEEDED = 'generation_succeeded';   // result stored + charged

    public const KIND_GENERATION_FAILED = 'generation_failed';         // released, NO charge

    public const KIND_GENERATION_CANCELLED = 'generation_cancelled';

    protected $fillable = [
        'site_id',
        'actor',
        'kind',
        'subject_type',
        'subject_id',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
