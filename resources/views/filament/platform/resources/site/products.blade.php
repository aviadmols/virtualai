{{-- P3 — platform per-site scan review/confirm. The table is built in
     ManageSiteProducts (HasTable); reads ride the audited PlatformProductQuery seam,
     the per-product Confirm runs through ConfirmScanAction (tenant-bound). Native
     Filament table chrome only — no inline CSS. --}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
