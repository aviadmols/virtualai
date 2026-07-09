<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\CostsMetricsBuilder;
use App\Filament\Platform\Concerns\ResolvesReportWindow;
use Filament\Widgets\Widget;

/**
 * P1 — provider spend. Shows how much real cost went to EACH AI provider (OpenRouter vs BytePlus)
 * over the selected window, as pre-formatted KPI cards. The split is computed by the one sanctioned
 * cross-account aggregate (CostsMetricsBuilder::byProvider); this widget only formats + labels it.
 */
class ProviderCostsWidget extends Widget
{
    use ResolvesReportWindow;

    // === CONSTANTS ===
    protected static string $view = 'filament.platform.widgets.provider-costs';

    protected int|string|array $columnSpan = 'full';

    // provider value => card i18n label key.
    private const LABELS = [
        ImageGenerationProvider::PROVIDER_OPENROUTER => 'platform.costs.providers.openrouter',
        ImageGenerationProvider::PROVIDER_BYTEPLUS => 'platform.costs.providers.byteplus',
        ImageGenerationProvider::PROVIDER_XAI => 'platform.costs.providers.xai',
        ImageGenerationProvider::PROVIDER_ATLASCLOUD => 'platform.costs.providers.atlascloud',
        ImageGenerationProvider::PROVIDER_FAL => 'platform.costs.providers.fal',
    ];

    private const LABEL_UNKNOWN = 'platform.costs.providers.unknown';

    private const TONE_NEUTRAL = 'neutral';

    /**
     * Render-ready provider cards: each carries its display label, the pre-formatted cost we paid
     * that provider, and the count. `hasData` is false until any provider has recorded spend.
     *
     * @return array{hasData:bool, cards:array<int,array{label:string,value:string,count:int}>}
     */
    public function getProviders(): array
    {
        $rows = app(CostsMetricsBuilder::class)->byProvider($this->reportWindow());

        $cards = [];
        $total = 0;

        foreach ($rows as $row) {
            $total += (int) $row['costMicroUsd'];
            $cards[] = [
                // The i18n KEY (the <x-to.kpi> card translates the label itself).
                'label' => self::LABELS[$row['provider']] ?? self::LABEL_UNKNOWN,
                'value' => $this->usd((int) $row['costMicroUsd']),
                'count' => (int) $row['count'],
            ];
        }

        return ['hasData' => $total > 0, 'cards' => $cards];
    }

    /** Neutral tone for every provider card (the value is a cost, not a good/bad signal). */
    public function tone(): string
    {
        return self::TONE_NEUTRAL;
    }

    /** Integer micro-USD → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }
}
