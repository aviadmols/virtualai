<?php

namespace App\Filament\Platform\Resources\MediaAssetResource\Pages;

use App\Filament\Platform\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateMediaAsset extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = MediaAssetResource::class;

    /**
     * Derive what the form doesn't ask: the kind (from the stored extension)
     * and the byte size (from the disk) — the URL screen shows both.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $path = (string) ($data['file_path'] ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $data['kind'] = MediaAsset::kindForExtension($extension);
        $data['size_bytes'] = (int) Storage::disk((string) config('trayon.media.disk'))->size($path);

        return $data;
    }
}
