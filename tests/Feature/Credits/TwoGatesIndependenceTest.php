<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\CreditGate;
use App\Domain\Credits\ReservationManager;
use App\Domain\Leads\LeadGate;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two gates NEVER collapse into one. CreditGate (merchant has credits) and
 * LeadGate (end user under the free limit or registered) are independent; both
 * must pass. This is the both-must-pass matrix:
 *
 *   credit pass + lead pass  -> allowed
 *   credit pass + lead fail  -> blocked (signup required) even though merchant paid
 *   credit fail + lead pass  -> blocked (out of credits) even for a registered user
 *   credit fail + lead fail  -> blocked
 */
class TwoGatesIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private const ESTIMATE = 1_000_000;

    /** @return array{0:Site,1:Account} a fresh site + its account ($5 balance). */
    private function siteWithCredit(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create([
            'free_generations_before_signup' => 2,
        ]);

        return [$site, $account];
    }

    /** Drain the account's spendable so the credit gate fails. */
    private function drainCredit(Account $account): void
    {
        $reservations = app(ReservationManager::class);
        Tenant::run($account, fn () => $reservations->reserve($account, 'drain-'.$account->id, 5_000_000));
        $account->refresh();
    }

    public function test_both_pass_is_allowed(): void
    {
        [$site, $account] = $this->siteWithCredit();
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(0)->create();

        $credit = CreditGate::for($account)->assertCanSpend(self::ESTIMATE);
        $lead = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($credit->passed && $lead->allowed);
    }

    public function test_credit_pass_lead_fail_is_blocked(): void
    {
        [$site, $account] = $this->siteWithCredit();
        // Lead exhausted: 2 used against a free limit of 2.
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(2)->create();

        $credit = CreditGate::for($account)->assertCanSpend(self::ESTIMATE);
        $lead = LeadGate::for($site, $endUser)->assertCanTry();

        // Merchant has credits, but the END USER is blocked — the gates did not collapse.
        $this->assertTrue($credit->passed);
        $this->assertTrue($lead->denied());
    }

    public function test_credit_fail_lead_pass_is_blocked(): void
    {
        [$site, $account] = $this->siteWithCredit();
        $this->drainCredit($account);
        // A REGISTERED user with grant would pass the lead gate.
        $endUser = EndUser::factory()->forSite($site)->registered()->withGenerationsUsed(0)->create();

        $credit = CreditGate::for($account)->assertCanSpend(self::ESTIMATE);
        $lead = LeadGate::for($site, $endUser)->assertCanTry();

        // End user is fine, but the MERCHANT is out of credits — independent gates.
        $this->assertTrue($lead->allowed);
        $this->assertTrue($credit->denied());
    }

    public function test_both_fail_is_blocked(): void
    {
        [$site, $account] = $this->siteWithCredit();
        $this->drainCredit($account);
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(2)->create();

        $credit = CreditGate::for($account)->assertCanSpend(self::ESTIMATE);
        $lead = LeadGate::for($site, $endUser)->assertCanTry();

        $this->assertTrue($credit->denied() && $lead->denied());
    }
}
