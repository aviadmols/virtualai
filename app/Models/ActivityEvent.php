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

    // Customer-Club (Phase 2): the shopper verified their email and became a member.
    public const KIND_CLUB_JOINED = 'club_joined';

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

    // Shopify app lifecycle (a store connected/disconnected the Vsio app).
    public const KIND_SHOPIFY_INSTALLED = 'shopify_installed';

    public const KIND_SHOPIFY_UNINSTALLED = 'shopify_uninstalled';

    // Shopify product sync (Phase 3). A product is never deleted — it is ARCHIVED, and
    // the archive leaves a trace so the merchant can see why it left their catalog.
    public const KIND_SHOPIFY_SYNC_STARTED = 'shopify_sync_started';

    public const KIND_SHOPIFY_SYNC_COMPLETED = 'shopify_sync_completed';

    public const KIND_SHOPIFY_SYNC_FAILED = 'shopify_sync_failed';

    // A catalog walk that hit its page budget with pages still unread. It is COMPLETED,
    // not failed — but it saw only part of the store, so it swept NOTHING (archiving on an
    // incomplete traversal would archive live products). The merchant is told, on the
    // timeline and in the import history, that the catalog was only partly walked.
    public const KIND_SHOPIFY_SYNC_TRUNCATED = 'shopify_sync_truncated';

    public const KIND_SHOPIFY_PRODUCT_IMPORTED = 'shopify_product_imported';

    public const KIND_SHOPIFY_PRODUCT_UPDATED = 'shopify_product_updated';

    public const KIND_SHOPIFY_PRODUCT_ARCHIVED = 'shopify_product_archived';

    // Product Image Studio (bulk AI image transforms). The batch bookends + every per-asset
    // status/review move (written by the models' guarded transitions).
    public const KIND_PRODUCT_IMAGE_BATCH_STARTED = 'product_image_batch_started';

    public const KIND_PRODUCT_IMAGE_BATCH_COMPLETED = 'product_image_batch_completed';

    public const KIND_PRODUCT_ASSET_STATUS_CHANGED = 'product_asset_status_changed';

    public const KIND_PRODUCT_ASSET_APPROVED = 'product_asset_approved';

    public const KIND_PRODUCT_ASSET_REJECTED = 'product_asset_rejected';

    // Phase 5 — pushing an APPROVED image into the store's product media. A push is FREE (no
    // ledger row, ever); these traces are how a merchant sees what happened to a LIVE gallery.
    public const KIND_PRODUCT_ASSET_PUSH_STATUS_CHANGED = 'product_asset_push_status_changed';

    public const KIND_SHOPIFY_MEDIA_PUSHED = 'shopify_media_pushed';

    public const KIND_SHOPIFY_MEDIA_PUSH_FAILED = 'shopify_media_push_failed';

    // The mandatory pre-flight of any DESTRUCTIVE push: our own copy of the original gallery.
    // Without it, Undo is a lie (Shopify drops the bytes when the media is deleted).
    public const KIND_SHOPIFY_MEDIA_SNAPSHOT_CAPTURED = 'shopify_media_snapshot_captured';

    public const KIND_SHOPIFY_MEDIA_SNAPSHOT_FAILED = 'shopify_media_snapshot_failed';

    public const KIND_SHOPIFY_MEDIA_RESTORED = 'shopify_media_restored';

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
