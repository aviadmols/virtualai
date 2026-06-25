<?php

namespace Tests\Feature\Leads;

use App\Domain\Leads\LeadCapture;
use App\Domain\Leads\LeadGate;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LeadCapture — turning an anonymous end user into a registered lead, and the
 * end-to-end "signup re-opens the gate" flow: an exhausted anonymous user is blocked,
 * signs up, and (with a post-signup grant) may try again.
 */
class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_stamps_registered_at_and_stores_fields(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $endUser = EndUser::factory()->forSite($site)->create();

        $capture = app(LeadCapture::class);
        Tenant::run($account, fn () => $capture->register($endUser, [
            'full_name' => 'Dana Levi',
            'email' => 'dana@example.com',
            'phone' => '+972500000000',
            'source' => 'widget',
            'utm' => ['campaign' => 'spring'],
        ]));

        $fresh = $endUser->fresh();
        $this->assertTrue($fresh->isRegistered());
        $this->assertSame('Dana Levi', $fresh->full_name);
        $this->assertSame('dana@example.com', $fresh->email);
        $this->assertSame(['campaign' => 'spring'], $fresh->utm);
    }

    public function test_register_records_a_lead_registered_activity(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $endUser = EndUser::factory()->forSite($site)->create();

        $capture = app(LeadCapture::class);
        Tenant::run($account, fn () => $capture->register($endUser, ['email' => 'a@b.com']));

        $traced = Tenant::run($account, fn () => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_LEAD_REGISTERED)
            ->where('subject_id', $endUser->id)
            ->exists());
        $this->assertTrue($traced);
    }

    public function test_signup_reopens_the_lead_gate(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create([
            'free_generations_before_signup' => 2,
            'post_signup_grant' => ['type' => LeadGate::GRANT_TYPE_LIMITED, 'amount' => 3],
        ]);

        // Anonymous user exhausted at 2/2 -> blocked.
        $endUser = EndUser::factory()->forSite($site)->withGenerationsUsed(2)->create();
        $this->assertTrue(LeadGate::for($site, $endUser)->assertCanTry()->signupRequired);

        // After signup, the post-signup grant re-opens the gate (2 + 3 = 5 ceiling).
        $capture = app(LeadCapture::class);
        Tenant::run($account, fn () => $capture->register($endUser, ['email' => 'x@y.com']));

        $this->assertTrue(LeadGate::for($site, $endUser->fresh())->assertCanTry()->allowed);
    }

    public function test_re_registering_does_not_re_stamp_registered_at(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $endUser = EndUser::factory()->forSite($site)->registered()->create();
        $firstRegisteredAt = $endUser->registered_at;

        $capture = app(LeadCapture::class);
        Tenant::run($account, fn () => $capture->register($endUser, ['phone' => '+10000000000']));

        $this->assertEquals($firstRegisteredAt, $endUser->fresh()->registered_at);
        $this->assertSame('+10000000000', $endUser->fresh()->phone);
    }
}
