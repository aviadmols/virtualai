<?php

namespace App\Domain\Club;

use App\Domain\Activity\ActivityRecorder;
use App\Models\ActivityEvent;
use App\Models\EndUser;

/**
 * ClubMembership — turns a verified end user into a Customer-Club member.
 *
 * Called ONLY after ClubVerification::verify() returned Verified. Stamps the email
 * + verified_at, and — because joining the club is an EXPLICIT opt-in that captures
 * the email and enables member behavioral tracking — records marketing consent, with
 * the intent traced. Consent is stamped the first time it flips true (mirrors
 * LeadCapture's GDPR-off-by-default pattern; never pre-checked, never implied by a
 * plain lead signup). Idempotent: re-verifying an already-verified member updates the
 * email without re-stamping verified_at and traces nothing new.
 */
final class ClubMembership
{
    // === CONSTANTS ===
    // The consent basis recorded on the activity trace (audit of WHY consent was set).
    private const CONSENT_BASIS = 'club_join';

    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Mark the end user a verified club member. Sets email + verified_at the first
     * time, opts them into marketing (club membership is the explicit opt-in), and
     * traces a club_joined event once.
     */
    public function join(EndUser $endUser, string $email): EndUser
    {
        $alreadyMember = $endUser->isClubMember();

        $endUser->email = $email;

        if (! $alreadyMember) {
            $endUser->verified_at = now();
        }

        // Club join IS the explicit marketing opt-in (email + behavioral tracking).
        // Stamp the consent + its timestamp only on the first flip (never re-stamp).
        if (! $endUser->hasMarketingConsent()) {
            $endUser->marketing_consent = true;
            $endUser->marketing_consent_at = now();
        }

        $endUser->save();

        if (! $alreadyMember) {
            $this->activity->record(
                kind: ActivityEvent::KIND_CLUB_JOINED,
                subject: $endUser,
                details: [
                    'email' => $endUser->email,
                    'marketing_consent' => true,
                    'consent_basis' => self::CONSENT_BASIS,
                ],
                siteId: $endUser->site_id,
                actor: ActivityEvent::ACTOR_END_USER,
            );
        }

        return $endUser;
    }
}
