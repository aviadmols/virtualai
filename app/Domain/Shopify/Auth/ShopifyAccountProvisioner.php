<?php

namespace App\Domain\Shopify\Auth;

use App\Domain\Accounts\AccountProvisioner;
use App\Domain\Shopify\Api\ShopProfile;
use App\Domain\Shopify\Api\ShopifyShopProfile;
use App\Http\Shopify\ShopifyShopRouter;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ShopifyAccountProvisioner — the Shopify-SSO onboarding path: turn a brand-new install
 * into a usable, logged-in merchant with NO manual register/login step.
 *
 * Reached ONLY from the HMAC-verified OAuth callback, and only after
 * ShopifyInstaller::reconnectKnownShop has ruled out a shop we already know. The shop
 * domain is the trust anchor (verified in the callback), so it is safe to mint an account
 * and log its owner in — only the real store admin who approved the grant gets here.
 *
 * It mints the Account + owner User (the opening grant fires via AccountObserver), then
 * reuses ShopifyInstaller's persist path for the Site + connection. Idempotent by
 * shop_domain: a re-run resolves the EXISTING owner + site — never a second account,
 * user, grant, or connection (the shop_domain unique index is the ultimate backstop).
 */
final class ShopifyAccountProvisioner
{
    // === CONSTANTS ===
    private const LOG_PROVISIONED = 'shopify.install.auto_provisioned';

    // Entropy for the owner's placeholder password (they authenticate via Shopify SSO and
    // never type it; a password-reset email restores manual access).
    private const OWNER_PASSWORD_LENGTH = 32;

    // Suffix length when a shop-derived email improbably collides with an existing user.
    private const EMAIL_SUFFIX_LENGTH = 6;

    public function __construct(
        private readonly AccountProvisioner $accounts,
        private readonly ShopifyInstaller $installer,
        private readonly ShopifyShopRouter $router,
        private readonly ShopifyShopProfile $profile,
    ) {}

    /**
     * Provision (idempotently) the account + owner + Site + connection for a brand-new
     * Shopify install, and return what the callback needs to log the owner in.
     */
    public function provisionForInstall(string $shopDomain, ShopifyAccessToken $token, string $correlationId): ShopifyProvisionResult
    {
        // Idempotency backstop: reconnectKnownShop handles a known shop before we are
        // called, but a race (two callbacks for the same new shop) is closed here — an
        // already-provisioned shop resolves its existing owner + site, never a duplicate.
        $existingAccountId = $this->router->accountIdForShopDomain($shopDomain);

        if ($existingAccountId !== null) {
            return $this->resolveExisting($existingAccountId, $shopDomain);
        }

        // Best-effort identity from Shopify (never blocks the install on an API hiccup).
        $shop = $this->profile->fetch($shopDomain, $token);
        $name = $this->resolveName($shop, $shopDomain);
        $email = $this->resolveOwnerEmail($shop->email, $shopDomain);

        $provisioned = $this->accounts->create(
            account: [
                'name' => $name,
                // The real store email for billing, even when the login falls back.
                'billing_email' => $shop->email,
                'locale' => Account::DEFAULT_LOCALE,
            ],
            owner: [
                'name' => $name,
                'email' => $email,
                'password' => Str::password(self::OWNER_PASSWORD_LENGTH),
                'email_verified' => true, // the email came from Shopify
            ],
        );

        $connection = $this->installer->installFreshShop(
            accountId: (int) $provisioned->account->getKey(),
            shopDomain: $shopDomain,
            token: $token,
            correlationId: $correlationId,
        );

        Log::info(self::LOG_PROVISIONED, [
            'correlation_id' => $correlationId,
            'shop_domain' => $shopDomain,
            'account_id' => (int) $provisioned->account->getKey(),
            'site_id' => (int) $connection->site_id,
        ]);

        return new ShopifyProvisionResult($provisioned->owner, (int) $connection->site_id);
    }

    // === Internals ===

    /**
     * The already-provisioned case (re-run / race): resolve the shop's existing owner +
     * site so the callback logs the SAME merchant in. A shop that routes to an account
     * with no readable owner/site is a corrupt state we refuse (typed, never a 500) rather
     * than log a stranger in.
     */
    private function resolveExisting(int $accountId, string $shopDomain): ShopifyProvisionResult
    {
        $siteId = Tenant::run($accountId, fn (): ?int => ShopifyConnection::query()
            ->where('shop_domain', $shopDomain)
            ->value('site_id'));

        $owner = $this->ownerFor($accountId);

        if ($owner === null || $siteId === null) {
            throw ShopifyOAuthException::noAccount();
        }

        return new ShopifyProvisionResult($owner, (int) $siteId);
    }

    /** An account's owner user (its earliest account-scoped, non-super-admin login). */
    private function ownerFor(int $accountId): ?User
    {
        return User::query()->forAccount($accountId)->orderBy('id')->first();
    }

    /**
     * The owner login email: the store's real email when present AND not already used by
     * an existing user — never hijack an email that belongs to a different account. Falls
     * back to a deterministic shop-derived login otherwise.
     */
    private function resolveOwnerEmail(?string $shopEmail, string $shopDomain): string
    {
        if ($shopEmail !== null && ! $this->emailExists($shopEmail)) {
            return $shopEmail;
        }

        return $this->uniqueEmail($this->deterministicEmail($shopDomain));
    }

    /** A shop-derived login, unique because the shop_domain is globally unique. */
    private function deterministicEmail(string $shopDomain): string
    {
        return Str::before($shopDomain, '.').'@'.$shopDomain;
    }

    /**
     * Guard the owner insert against a unique-index 500: a genuinely new shop's derived
     * email should not pre-exist, but suffix it in the improbable collision.
     */
    private function uniqueEmail(string $email): string
    {
        if (! $this->emailExists($email)) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return $local.'+'.Str::lower(Str::random(self::EMAIL_SUFFIX_LENGTH)).'@'.$domain;
    }

    private function emailExists(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /** The account + owner display name: the store name, else the shop handle, headlined. */
    private function resolveName(ShopProfile $shop, string $shopDomain): string
    {
        return $shop->name ?? Str::headline(Str::before($shopDomain, '.'));
    }
}
