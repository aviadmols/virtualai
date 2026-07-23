{{-- Vsio wordmark for the panel header. Two files ship; the light/dark swap is
     driven by Filament's `.dark` root class in brand-logo.css (height per panel).
     Filenames are versioned (…-v2) because the media host caches static assets
     immutably by PATH — a content change needs a fresh path to bust the edge. --}}
<img src="{{ asset('vsio-logo-v2.svg') }}" alt="Vsio" class="to-brand-logo to-brand-logo--light" />
<img src="{{ asset('vsio-logo-v2-dark.svg') }}" alt="Vsio" class="to-brand-logo to-brand-logo--dark" />
