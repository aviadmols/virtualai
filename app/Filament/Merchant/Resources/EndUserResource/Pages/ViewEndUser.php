<?php

namespace App\Filament\Merchant\Resources\EndUserResource\Pages;

use App\Domain\Activity\EndUserActivityTimeline;
use App\Domain\Leads\LeadAttemptHistory;
use App\Filament\Merchant\Resources\EndUserResource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * M6 / A7 — the lead card. A record-bound resource page: ViewRecord resolves the
 * {record} through the resource's account-scoped query (the merchant tenant
 * binding), so a merchant can only ever open their own account's leads. The
 * attempt history is read through LeadAttemptHistory::for() — account-scoped to
 * the lead's own account — so this page never queries generations itself; a
 * purged result is surfaced as a placeholder, never a broken image.
 */
class ViewEndUser extends \Filament\Resources\Pages\ViewRecord
{
    // === CONSTANTS ===
    protected static string $resource = EndUserResource::class;

    protected static string $view = 'filament.merchant.resources.end-user.view';

    /** The localised page heading — the lead's name (or an anonymous fallback). */
    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->full_name
            ?: ($this->getRecord()->email ?: __('leads.anonymous'));
    }

    /** The lead's attempts (newest first) as immutable LeadAttempt DTOs. */
    public function getAttempts(): Collection
    {
        return app(LeadAttemptHistory::class)->for($this->getRecord());
    }

    /**
     * The lead's activity timeline (newest first) as immutable EndUserActivityItem
     * DTOs — everything this shopper did on the shop. Account-scoped through
     * EndUserActivityTimeline::for(); this page never queries activity_events itself.
     */
    public function getTimeline(): Collection
    {
        return app(EndUserActivityTimeline::class)->for($this->getRecord());
    }
}
