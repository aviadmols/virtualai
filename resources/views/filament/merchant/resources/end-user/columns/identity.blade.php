{{-- Leads list identity cell — warm-gradient initials avatar + display name (name -> email ->
     anonymous). Identity logic reuses <x-to.avatar>; the record is already account+tenant scoped. --}}
@php($record = $getRecord())
<div class="to-lead-identity">
    <x-to.avatar :name="$record->full_name" :email="$record->email" />
    <span class="to-lead-identity__name">{{ $record->full_name ?: ($record->email ?: __('leads.anonymous')) }}</span>
</div>
