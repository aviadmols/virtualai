{{--
    P5 — Prompt edit page = the native Filament edit form + the resolver-preview
    panel beneath it. Mirrors Filament's stock edit-record view (so the form,
    actions and unsaved-changes guard behave natively), then mounts the
    PromptResolverPreview Livewire component seeded with this prompt's operation_key
    + product_type. The preview is strtr-safe + read-only (G9).

    TOKENS: resolver-preview.css (the panel). No inline CSS here.
--}}
<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    <x-filament-panels::form
        id="form"
        :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
        wire:submit="save"
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    {{-- The resolver-preview panel: which model + prompt this operation resolves to. --}}
    @livewire(\App\Filament\Platform\Resources\PromptResource\PromptResolverPreview::class, [
        'operationKey' => $this->previewOperationKey(),
        'productType' => $this->previewProductType(),
    ])

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
