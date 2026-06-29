<?php

namespace App\Filament\Platform\Resources\PromptResource\Pages;

use App\Filament\Platform\Resources\PromptResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * P5 — Create a prompt. The resolver-preview panel appears on the Edit page (it
 * needs a saved operation_key to resolve against), so create is a plain form.
 */
class CreatePrompt extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = PromptResource::class;
}
