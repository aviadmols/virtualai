{{--
    M6 / A7 — lead card page view. Wraps the <x-to.lead-card> component in the
    Filament page chrome. The attempts are LeadAttempt DTOs from the page
    ($this->getAttempts(), via LeadAttemptHistory) — account-scoped, with signed
    thumbnails or a purged flag; the component renders, never queries.

    TOKENS: lead-card.css (via <x-to.lead-card>).
    i18n: leads.*, status.lead.*, status.generation.* (inside the component).
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
</x-filament-panels::page>
