<?php

namespace App\Domain\Leads;

use App\Models\EndUser;
use App\Models\Site;

/**
 * LeadGate — is the END USER allowed another free try? One of the two independent
 * gates (the other is CreditGate: does the merchant have credits). Both must pass;
 * they NEVER collapse into one. A registered end user with grant still cannot
 * generate if the merchant is out of credits, and a merchant with credits still
 * cannot serve an unregistered end user past the free limit.
 *
 * Rules (per-site free_generations_before_signup, read from the Site):
 *  - null  -> signup NEVER required; always allowed.
 *  - 0     -> signup required BEFORE the first try (an anonymous user is blocked).
 *  - N     -> N free tries before signup; the (N+1)th requires signup.
 *
 * After signup (registered_at set) the site's post_signup_grant decides:
 *  - {type:'unlimited'}        -> always allowed.
 *  - {type:'limited', amount:M}-> allowed up to free_before + M total generations.
 *  - ABSENT (null)             -> defaults to UNLIMITED: signing up UNLOCKS continued
 *                                 try-ons (gated by the merchant's CreditGate) — the value
 *                                 exchange for the lead. A dead-end here would loop the
 *                                 signup form forever for an already-registered user.
 *  - explicit {type:'none'}    -> no extra (a merchant who deliberately wants the free
 *                                 count to be the hard cap even after signup).
 *
 * The gate is read-only and returns a TYPED LeadDecision — never throws for the
 * signup-required path, never a 500. The widget renders the signup form on a deny.
 *
 * Usage: LeadGate::for($site, $endUser)->assertCanTry()
 */
final class LeadGate
{
    // === CONSTANTS ===
    public const GRANT_TYPE_UNLIMITED = 'unlimited';
    public const GRANT_TYPE_LIMITED = 'limited';
    public const GRANT_TYPE_NONE = 'none';

    private const GRANT_TYPE_KEY = 'type';
    private const GRANT_AMOUNT_KEY = 'amount';

    private function __construct(
        private readonly Site $site,
        private readonly EndUser $endUser,
    ) {}

    public static function for(Site $site, EndUser $endUser): self
    {
        return new self($site, $endUser);
    }

    /**
     * Decide whether this end user may run another generation. Typed result; the
     * caller never catches an exception here.
     */
    public function assertCanTry(): LeadDecision
    {
        $freeBefore = $this->site->free_generations_before_signup;
        $used = $this->endUser->generations_used;

        // null -> signup never required; always allowed.
        if ($freeBefore === null) {
            return LeadDecision::allow();
        }

        // Registered users are governed by the post-signup grant, not the free count.
        if ($this->endUser->isRegistered()) {
            return $this->decideRegistered($freeBefore, $used);
        }

        // Anonymous: allowed while still under the free-tries limit.
        if ($used < $freeBefore) {
            return LeadDecision::allow($freeBefore - $used);
        }

        // Free tries exhausted (or 0 = signup before first try) -> signup required.
        return LeadDecision::signupRequired();
    }

    /** Convenience boolean for callers that only need a yes/no. */
    public function canTry(): bool
    {
        return $this->assertCanTry()->allowed;
    }

    /**
     * A registered end user: the post_signup_grant decides. Unlimited always passes;
     * limited allows up to free_before + amount total; none/absent keeps the free
     * count (which a registered-but-no-grant user has typically already spent).
     */
    private function decideRegistered(int $freeBefore, int $used): LeadDecision
    {
        $grant = $this->site->post_signup_grant ?? [];
        // An ABSENT grant defaults to UNLIMITED (signup unlocks continued use); only an
        // EXPLICIT {type:'none'} keeps the free count as the hard cap.
        $type = $grant[self::GRANT_TYPE_KEY] ?? self::GRANT_TYPE_UNLIMITED;

        if ($type === self::GRANT_TYPE_UNLIMITED) {
            return LeadDecision::allow();
        }

        if ($type === self::GRANT_TYPE_LIMITED) {
            $amount = (int) ($grant[self::GRANT_AMOUNT_KEY] ?? 0);
            $ceiling = $freeBefore + $amount;

            if ($used < $ceiling) {
                return LeadDecision::allow($ceiling - $used);
            }

            return LeadDecision::postSignupLimitReached();
        }

        // No extra grant: a registered user falls back to the plain free count.
        if ($used < $freeBefore) {
            return LeadDecision::allow($freeBefore - $used);
        }

        return LeadDecision::postSignupLimitReached();
    }
}
