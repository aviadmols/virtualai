<?php

namespace Tests\Feature\Filament\Merchant;

use App\Models\Account;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Direct email/password login for SSO-provisioned merchants.
 *
 * A Shopify install auto-creates the owner with a RANDOM password (they only ever
 * authenticate via the embedded session token). Enabling ->profile() + ->passwordReset()
 * on the merchant panel lets that owner set a password from the account menu and then
 * sign in at /merchant/login directly — no manual registration, no known password needed.
 */
class MerchantDirectLoginTest extends TestCase
{
    use RefreshDatabase;

    // The exact password an SSO-provisioned merchant sets to enable direct login.
    private const NEW_PASSWORD = 'Aa45804580$';

    public function test_merchant_panel_exposes_the_profile_route(): void
    {
        // ->profile() is what lets an SSO-provisioned merchant set a password and sign in directly.
        // (Email ->passwordReset() is deferred until SMTP is configured — see MerchantPanelProvider.)
        $this->assertTrue(Route::has('filament.merchant.auth.profile'), 'profile route missing');
    }

    public function test_provisioned_owner_can_set_a_password_then_authenticate(): void
    {
        $account = Account::factory()->create();
        // Mirrors a provisioned owner: account-scoped, not super-admin, unknown random password.
        $owner = User::factory()->forAccount($account)->create([
            'is_super_admin' => false,
            'password' => 'a-random-unknown-secret',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($owner);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => self::NEW_PASSWORD,
                'passwordConfirmation' => self::NEW_PASSWORD,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // The merchant can now actually sign in with the password they chose — an end-to-end
        // attempt through the web guard, not just a stored-hash check.
        $this->assertTrue(
            Auth::guard('web')->attempt(['email' => $owner->email, 'password' => self::NEW_PASSWORD]),
            'the provisioned merchant should be able to sign in with the password they set',
        );
    }

    public function test_profile_form_cannot_escalate_privileges_or_reassign_account(): void
    {
        $account = Account::factory()->create();
        $foreignAccount = Account::factory()->create();
        $owner = User::factory()->forAccount($account)->create(['is_super_admin' => false]);

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($owner);

        // Injecting the sensitive columns into the form state must NOT persist them: the default
        // EditProfile schema is name/email/password only, so account ownership and super-admin
        // stay exactly where provisioning put them.
        Livewire::test(EditProfile::class)
            ->set('data.is_super_admin', true)
            ->set('data.account_id', $foreignAccount->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $owner->fresh();
        $this->assertFalse((bool) $fresh->is_super_admin, 'is_super_admin must not be escalatable via the profile page');
        $this->assertSame($account->id, $fresh->account_id, 'account_id must not be reassignable via the profile page');
    }
}
