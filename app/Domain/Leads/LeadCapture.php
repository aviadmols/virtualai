<?php

namespace App\Domain\Leads;

use App\Domain\Activity\ActivityRecorder;
use App\Models\ActivityEvent;
use App\Models\EndUser;

/**
 * LeadCapture — turns an anonymous end user into a registered lead.
 *
 * On signup it records the captured fields (full name, email, phone), stamps
 * registered_at, stores acquisition source/utm, and leaves an activity trace. The
 * post-signup grant itself is applied by the LeadGate at decision time (it reads
 * the site's post_signup_grant), so capture only marks the lead registered.
 *
 * Idempotent: re-capturing an already-registered lead just updates the fields and
 * does not re-stamp registered_at.
 */
final class LeadCapture
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Register the end user (signup). Sets registered_at the first time, updates
     * contact + attribution fields, traces the event.
     *
     * GDPR consent: marketing_consent is read ONLY from an EXPLICIT, truthy
     * `marketing_consent` field — it defaults OFF and is never implied by signup itself.
     * marketing_consent_at is stamped the first time it flips true.
     *
     * @param  array<string,mixed>  $fields  full_name, email, phone, source, utm, marketing_consent
     */
    public function register(EndUser $endUser, array $fields): EndUser
    {
        $endUser->fill([
            'full_name' => $fields['full_name'] ?? $endUser->full_name,
            'email' => $fields['email'] ?? $endUser->email,
            'phone' => $fields['phone'] ?? $endUser->phone,
            'source' => $fields['source'] ?? $endUser->source,
            'utm' => $fields['utm'] ?? $endUser->utm,
        ]);

        // Marketing is opt-in: only an explicit truthy value sets it; absence keeps OFF.
        $optedIn = filter_var($fields['marketing_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($optedIn && ! $endUser->hasMarketingConsent()) {
            $endUser->marketing_consent = true;
            $endUser->marketing_consent_at = now();
        }

        $alreadyRegistered = $endUser->isRegistered();

        if (! $alreadyRegistered) {
            $endUser->registered_at = now();
        }

        $endUser->save();

        if (! $alreadyRegistered) {
            $this->activity->record(
                kind: ActivityEvent::KIND_LEAD_REGISTERED,
                subject: $endUser,
                details: ['email' => $endUser->email],
                siteId: $endUser->site_id,
                actor: ActivityEvent::ACTOR_END_USER,
            );
        }

        return $endUser;
    }
}
