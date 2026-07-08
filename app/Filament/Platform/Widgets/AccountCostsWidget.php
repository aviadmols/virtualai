<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Filament\Platform\Concerns\ResolvesReportWindow;
use Filament\Widgets\Widget;

/**
 * P1 — per-account cost vs revenue. A table of each merchant account over the selected window: the
 * real cost we paid the providers vs the selling value the account was billed, with margin, realized
 * markup + charge count. Computed by CostsMetricsBuilder::byAccount (sums-only cross-account
 * aggregate, top spenders first); this widget only formats the rows.
 */
class AccountCostsWidget extends Widget
{
    use ResolvesReportWindow;

    // === CONSTANTS ===
    protected static string $view = 'filament.platform.widgets.account-costs';

    protected int|string|array $columnSpan = 'full';

    private const EMPTY_VALUE = '—';

    /**
     * Render-ready account rows (already formatted). `hasData` is false until any account has charges.
     *
     * @return array{hasData:bool, rows:array<int,array{name:string,cost:string,revenue:string,margin:string,marginNegative:bool,markup:string,charges:string}>}
     */
    public function getAccounts(): array
    {
        $accounts = app(CostsMetricsBuilder::class)->byAccount($this->reportWindow());

        $rows = [];
        foreach ($accounts as $a) {
            $rows[] = [
                'name' => $a['accountName'],
                'cost' => $this->usd($a['costMicroUsd']),
                'revenue' => $this->usd($a['revenueMicroUsd']),
                'margin' => $this->usd($a['marginMicroUsd']),
                'marginNegative' => $a['marginMicroUsd'] < 0,
                'markup' => $a['costMicroUsd'] > 0 ? number_format($a['markupRealized'], 2).'×' : self::EMPTY_VALUE,
                'charges' => number_format($a['charges']),
            ];
        }

        return ['hasData' => $rows !== [], 'rows' => $rows];
    }

    /** Integer micro-USD → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }
}
