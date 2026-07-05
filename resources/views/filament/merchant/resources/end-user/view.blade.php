{{--
    M6 / A7 + WS3 — lead card page view. Wraps the <x-to.lead-card> component and
    the per-end-user <x-to.activity-timeline> in the Filament page chrome. The
    attempts (LeadAttempt) and timeline events (EndUserActivityItem) are immutable
    DTOs from the page ($this->getAttempts() via LeadAttemptHistory,
    $this->getTimeline() via EndUserActivityTimeline) — both account-scoped; the
    components render, never query.

    TOKENS: lead-card.css, activity-timeline.css (via the components).
    i18n: leads.*, status.lead.*, status.generation.*, activity.* (inside the components).
--}}
<x-filament-panels::page>
    @php($lead = $this->getRecord())
    <x-to.lead-card
        :name="$lead->full_name"
        :email="$lead->email"
        :phone="$lead->phone"
        :status="$lead->status"
        :attempts="$this->getAttempts()"
    />

    <x-to.activity-timeline :events="$this->getTimeline()" />
</x-filament-panels::page>
