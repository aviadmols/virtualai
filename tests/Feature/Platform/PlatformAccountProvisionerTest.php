<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\PlatformAccountProvisioner;
use App\Exceptions\PlatformAccessRequiredException;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Platform-admin account provisioning (account + owner login, atomic).
 *
 * Proves a super-admin can provision a usable merchant account: the account row, its
 * non-super-admin owner user (password HASHED), and the opening grant (written by
 * AccountObserver) all land together; a duplicate owner email rolls the whole thing back
 * (no half-provisioned account); and a non-super-admin caller fails loud.
 */
class PlatformAccountProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'name' => 'Acme',
        'company_name' => 'Acme Inc',
        'billing_email' => 'billing@acme.test',
        'locale' => 'he',
        'owner_name' => 'Owner One',
        'owner_email' => 'owner@acme.test',
        'owner_password' => 'secret-password',
    ];

    public function test_provision_creates_account_owner_and_opening_grant(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $account = app(PlatformAccountProvisioner::class)->provision(self::VALID);

        $this->assertSame('Acme', $account->name);
        $this->assertSame('he', $account->locale);
        $this->assertTrue($account->isActive());

        // Owner: account-scoped, NOT a super-admin, password stored hashed.
        $owner = User::query()->where('email', 'owner@acme.test')->first();
        $this->assertNotNull($owner);
        $this->assertSame($account->id, (int) $owner->account_id);
        $this->assertFalse($owner->isSuperAdmin());
        $this->assertNotSame('secret-password', $owner->getAttribute('password'));
        $this->assertTrue(Hash::check('secret-password', $owner->getAttribute('password')));

        // The opening $5 grant (AccountObserver) is reflected in the balance + a grant row.
        $this->assertGreaterThan(0, $account->fresh()->balance_micro_usd);
        $grantRows = Tenant::run($account, fn (): int => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_GRANT)->count());
        $this->assertSame(1, $grantRows);
    }

    public function test_duplicate_owner_email_rolls_back_the_whole_account(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        User::factory()->create(['email' => 'taken@acme.test']);

        $before = Account::query()->count();

        try {
            app(PlatformAccountProvisioner::class)->provision([
                'name' => 'Should Not Persist',
                'owner_name' => 'Dupe',
                'owner_email' => 'taken@acme.test',
                'owner_password' => 'secret-password',
            ]);
            $this->fail('Expected the duplicate owner email to fail the provision.');
        } catch (\Throwable) {
            // expected — the unique users.email index rejects it
        }

        // The transaction rolled back: no half-provisioned account left behind.
        $this->assertSame($before, Account::query()->count());
        $this->assertSame(0, Account::query()->where('name', 'Should Not Persist')->count());
    }

    public function test_a_merchant_cannot_provision_an_account(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->forAccount($account)->create());

        $this->expectException(PlatformAccessRequiredException::class);
        app(PlatformAccountProvisioner::class)->provision(self::VALID);
    }

    public function test_an_unauthenticated_caller_cannot_provision(): void
    {
        Auth::logout();

        $this->expectException(PlatformAccessRequiredException::class);
        app(PlatformAccountProvisioner::class)->provision(self::VALID);
    }
}
