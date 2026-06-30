<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Platform\QueueHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Queue & worker health — the background-work status the super-admin needs to know the
 * scan/generation pipeline is actually running. Shows whether a Horizon worker is alive,
 * how many jobs are waiting, and how many failed. The "Worker" stat links to the full
 * Horizon control panel (/horizon). Reads a fail-soft QueueHealth snapshot, so a downed
 * worker shows "Inactive" rather than breaking the dashboard.
 */
class QueueHealthWidget extends StatsOverviewWidget
{
    // === CONSTANTS ===
    private const HORIZON_URL = '/horizon';

    protected static ?int $sort = -1; // top of the dashboard — status first

    protected function getStats(): array
    {
        $health = app(QueueHealth::class)->snapshot();
        $active = $health['worker_active'];
        $pending = $health['pending'];
        $failed = $health['failed'];

        return [
            Stat::make(
                __('platform.health.worker'),
                __($active ? 'platform.health.active' : 'platform.health.inactive'),
            )
                ->description(__($active ? 'platform.health.worker_ok' : 'platform.health.worker_down'))
                ->descriptionIcon($active ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($active ? 'success' : 'danger')
                ->url(self::HORIZON_URL, shouldOpenInNewTab: true),

            Stat::make(__('platform.health.pending'), (string) $pending)
                ->description(__('platform.health.pending_sub'))
                ->descriptionIcon('heroicon-m-clock')
                ->color(($pending > 0 && ! $active) ? 'warning' : 'gray'),

            Stat::make(__('platform.health.failed'), (string) $failed)
                ->description(__('platform.health.failed_sub'))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($failed > 0 ? 'danger' : 'gray')
                ->url(self::HORIZON_URL.'/failed', shouldOpenInNewTab: true),
        ];
    }
}
