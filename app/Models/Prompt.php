<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prompt — a per-operation prompt template, resolved site -> account ->
 * product_type -> global (first match wins; global ALWAYS exists).
 *
 * NOT BelongsToAccount by design (see the migration docblock): the table mixes
 * PLATFORM-GLOBAL rows (scope=global / scope=product_type, account_id NULL) with
 * TENANT-OWNED rows (scope=account / scope=site, account_id NOT NULL). A
 * fail-closed global scope would hide the global rows too, so isolation for the
 * tenant rows is enforced explicitly by the resolver's scopes below: an
 * account/site lookup is ALWAYS constrained by account_id (no cross-account read).
 *
 * On GlobalModels::ALLOW_LIST so the isolation audit treats it as audited-global
 * and looks here for the explicit account scoping instead of an automatic one.
 */
class Prompt extends Model
{
    /** @use HasFactory<\Database\Factories\PromptFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_PRODUCT_TYPE = 'product_type';
    public const SCOPE_ACCOUNT = 'account';
    public const SCOPE_SITE = 'site';

    // Resolution precedence — most specific first. The resolver walks this order.
    public const RESOLUTION_ORDER = [
        self::SCOPE_SITE,
        self::SCOPE_ACCOUNT,
        self::SCOPE_PRODUCT_TYPE,
        self::SCOPE_GLOBAL,
    ];

    // The platform-global scopes (account_id NULL); the rest are tenant-owned.
    public const GLOBAL_SCOPES = [
        self::SCOPE_GLOBAL,
        self::SCOPE_PRODUCT_TYPE,
    ];

    protected $fillable = [
        'scope',
        'operation_key',
        'product_type',
        'account_id',
        'site_id',
        'system_prompt',
        'user_prompt',
        'version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The active site-scoped prompt for an account+site+operation.
     * ALWAYS constrained by account_id — no cross-account read.
     *
     * @param  Builder<Prompt>  $query
     */
    public function scopeSiteScoped(Builder $query, int $accountId, int $siteId, string $operationKey): Builder
    {
        return $query->where('scope', self::SCOPE_SITE)
            ->where('account_id', $accountId)
            ->where('site_id', $siteId)
            ->where('operation_key', $operationKey)
            ->where('is_active', true);
    }

    /**
     * The active account-scoped prompt. ALWAYS constrained by account_id.
     *
     * @param  Builder<Prompt>  $query
     */
    public function scopeAccountScoped(Builder $query, int $accountId, string $operationKey): Builder
    {
        return $query->where('scope', self::SCOPE_ACCOUNT)
            ->where('account_id', $accountId)
            ->where('operation_key', $operationKey)
            ->where('is_active', true);
    }

    /**
     * The active product_type-scoped prompt (global — no tenant).
     *
     * @param  Builder<Prompt>  $query
     */
    public function scopeProductTypeScoped(Builder $query, string $productType, string $operationKey): Builder
    {
        return $query->where('scope', self::SCOPE_PRODUCT_TYPE)
            ->whereNull('account_id')
            ->where('product_type', $productType)
            ->where('operation_key', $operationKey)
            ->where('is_active', true);
    }

    /**
     * The active global prompt (the guaranteed floor — no tenant).
     *
     * @param  Builder<Prompt>  $query
     */
    public function scopeGlobalScoped(Builder $query, string $operationKey): Builder
    {
        return $query->where('scope', self::SCOPE_GLOBAL)
            ->whereNull('account_id')
            ->where('operation_key', $operationKey)
            ->where('is_active', true);
    }
}
