{{--
    Per-shop try-on prompt editor. The merchant tunes the wording that drives the try-on image
    and weaves in the product's own fields with {{tokens}} (filled at generation time from
    ProductFacts, substituted with strtr — never Blade). Reads everything off $this
    (the TryOnPrompt page): the form, the token list, hasSite. No inline CSS.
--}}
<x-filament-panels::page>
    @if ($hasSite)
        <form wire:submit="save" class="fi-form grid gap-y-6">
            {{ $this->form }}

            {{-- The product fields the prompt may reference. Click-to-read chips; the merchant
                 types the token where they want the product's value woven into the prompt. --}}
            <x-filament::section>
                <x-slot:heading>{{ __('try_on_prompt.tokens.title') }}</x-slot:heading>
                <x-slot:description>{{ __('try_on_prompt.tokens.sub') }}</x-slot:description>

                <div class="fi-fo-field-wrp-hint flex flex-wrap gap-2">
                    @foreach ($this->tokenExamples() as $example)
                        <x-filament::badge>{{ $example }}</x-filament::badge>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="flex justify-end">
                <x-filament::button type="submit">
                    {{ __('try_on_prompt.save') }}
                </x-filament::button>
            </div>
        </form>
    @else
        <x-filament::section>
            <p>{{ __('try_on_prompt.no_site') }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
