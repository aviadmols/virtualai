<?php

namespace App\Filament\Platform\Resources\AiModelResource\Pages;

use App\Filament\Platform\Resources\AiModelResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * P4 — Create an AI model. The cost hint converts USD <-> integer micro-USD entirely in
 * the form field (formatStateUsing / dehydrateStateUsing on AiModelResource), so this
 * page performs NO cost mutation — a second conversion here nulled the price on save.
 */
class CreateAiModel extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = AiModelResource::class;
}
