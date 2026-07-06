<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * CreditLedger — one immutable row of the money truth. Account-scoped
 * (BelongsToAccount): credits are shared across an account's sites, and a row is
 * always read under its owning tenant.
 *
 * APPEND-ONLY by construction:
 *  - no $timestamps update (created_at only; the table has no updated_at);
 *  - the `updating` and `deleting` model events THROW, so neither an accidental
 *    ->save() on a loaded row nor a ->delete() can mutate the financial record.
 *  Corrections are NEW rows (a refund reverses a charge; an adjustment is admin ±).
 *
 * The ONLY sanctioned writer is CreditLedgerService — it stamps balance_after in
 * the same transaction that mutates the account balance. Nothing else inserts here.
 */
class CreditLedger extends Model
{
    use BelongsToAccount;

    // === CONSTANTS ===
    protected $table = 'credit_ledger';

    // The ledger is append-only: created_at is managed manually (useCurrent in the
    // migration / set by the writer); there is no updated_at column.
    public $timestamps = false;

    // The five row types (ARCHITECTURE.md). grant/purchase/refund are positive;
    // charge is negative; adjustment is either sign.
    public const TYPE_GRANT = 'grant';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_CHARGE = 'charge';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_GRANT,
        self::TYPE_PURCHASE,
        self::TYPE_CHARGE,
        self::TYPE_REFUND,
        self::TYPE_ADJUSTMENT,
    ];

    // Reference subjects a row can point at.
    public const REFERENCE_GENERATION = 'generation';
    public const REFERENCE_BANNER_ASSET = 'banner_asset';
    public const REFERENCE_PURCHASE = 'purchase';

    private const APPEND_ONLY_MESSAGE = 'credit_ledger is append-only: a %s row cannot be %s. Write a new compensating row instead.';

    // account_id is stamped by BelongsToAccount; all other money columns are set
    // by the writer (CreditLedgerService), never from request input.
    //
    // N1: idempotency_key has a GLOBAL unique index (see the migration). That is safe
    // ONLY because every key built by IdempotencyKey embeds account_id as its first
    // segment, so two accounts can never collide on one key. Do not add a key that
    // omits account_id without making the index composite.
    protected $fillable = [
        'type',
        'amount_micro_usd',
        'balance_after_micro_usd',
        'idempotency_key',
        'reference_type',
        'reference_id',
        'actual_cost_micro_usd',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_micro_usd' => 'integer',
            'balance_after_micro_usd' => 'integer',
            'actual_cost_micro_usd' => 'integer',
            'reference_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only enforcement: a loaded row can never be updated or deleted.
        // A correction is a new row, never an edit (the money-safety law).
        static::updating(function (CreditLedger $row): void {
            throw new RuntimeException(sprintf(self::APPEND_ONLY_MESSAGE, $row->type, 'updated'));
        });

        static::deleting(function (CreditLedger $row): void {
            throw new RuntimeException(sprintf(self::APPEND_ONLY_MESSAGE, $row->type, 'deleted'));
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** True for a debit (a charge). Charges are the only negative-amount type by intent. */
    public function isCharge(): bool
    {
        return $this->type === self::TYPE_CHARGE;
    }
}
