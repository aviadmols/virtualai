<?php

namespace App\Filament\Platform\Resources\PromptResource\Pages;

use App\Filament\Platform\Resources\PromptResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * P5 — Edit a prompt + the RESOLVER-PREVIEW panel. A custom page view renders the
 * native Filament edit form and, below it, the PromptResolverPreview Livewire
 * component seeded with this prompt's operation_key + product_type — so an admin
 * can see exactly which model + prompt the operation resolves to (and why) without
 * running a generation. The preview is strtr-safe + read-only (G9).
 */
class EditPrompt extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = PromptResource::class;

    // Custom view = the stock edit form + the resolver-preview panel beneath it.
    protected static string $view = 'filament.platform.resources.prompt.edit';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** The operation_key the preview resolves against (this prompt's operation). */
    public function previewOperationKey(): string
    {
        return (string) $this->getRecord()->operation_key;
    }

    /** The product_type to seed the preview with (only meaningful for that scope). */
    public function previewProductType(): ?string
    {
        return $this->getRecord()->product_type;
    }
}
