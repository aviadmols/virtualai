{{--
    P5 — the resolver-preview panel (Livewire). Renders AiOperationResolver::preview()
    for an (operation, optional site, optional product_type): the winning model +
    prompt + the resolution trace + a SAMPLE substitution.

    G9 (template safety): the substitution is OperationPreview::renderUserPrompt()
    which is strtr ONLY (never Blade::render). The substituted text is echoed via
    Blade's {{ }} (htmlspecialchars auto-escape) into a read-only <pre> mono surface
    — the value is rendered as ESCAPED TEXT, never as HTML, so a value containing
    {{ 7*7 }} / @php / <script> appears verbatim and never executes. No HTTP call,
    no DB write (preview() guarantees this).

    TOKENS: resolver-preview.css (.to-rp*). i18n: platform.resolver.*
    Livewire requires a SINGLE root element — the root <section> wraps everything;
    $preview / $sampleVars come from render() data and the outcome tone from the
    component method, so no @php block renders as a sibling of the root.
--}}
<section class="to-rp">
    <header class="to-rp__head">
        <div>
            <h3 class="to-rp__title">{{ __('platform.resolver.title') }}</h3>
            <p class="to-rp__sub">{{ __('platform.resolver.sub') }}</p>
        </div>
    </header>

    {{-- Inputs: operation is fixed from the prompt; site + product_type optional. --}}
    <div class="to-rp__inputs">
        <label class="to-rp__field">
            <span class="to-rp__label">{{ __('platform.resolver.input.site') }}</span>
            <select class="to-rp__select" wire:model.live="siteId">
                @foreach($this->siteOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="to-rp__field">
            <span class="to-rp__label">{{ __('platform.resolver.input.product_type') }}</span>
            <input type="text" class="to-rp__input" wire:model.live="productType" />
        </label>
        <button type="button" class="to-rp__run" wire:click="runPreview" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="runPreview">{{ __('platform.resolver.preview') }}</span>
            <span wire:loading wire:target="runPreview">{{ __('platform.resolver.state.resolving') }}</span>
        </button>
    </div>

    @if($this->isError())
        <div class="to-rp__alert to-rp__alert--error">{{ __('platform.resolver.state.error') }}</div>
    @elseif($preview === null)
        <div class="to-rp__alert to-rp__alert--idle">{{ __('platform.resolver.state.idle') }}</div>
    @else
        {{-- ===== MODEL ===== --}}
        <div class="to-rp__block">
            <h4 class="to-rp__block-title">{{ __('platform.resolver.model.title') }}</h4>
            <dl class="to-rp__kv">
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.model.winning') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">{{ $preview->winningModel }}</dd>
                </div>
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.model.fallback') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">{{ $preview->fallbackModel ?? __('platform.resolver.model.none_fallback') }}</dd>
                </div>
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.model.chain') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">{{ implode(' → ', $preview->modelChain) }}</dd>
                </div>
            </dl>
            <ol class="to-rp__trace">
                @foreach($preview->modelTrace->steps as $step)
                    <li class="to-rp__trace-step">
                        <span class="to-rp__trace-level">{{ $step->level }}</span>
                        <span class="to-badge to-badge--{{ $this->outcomeTone($step->outcome) }}">
                            <span class="to-badge__dot" aria-hidden="true"></span>
                            {{ __('platform.resolver.outcome.'.$step->outcome) }}
                        </span>
                        <span class="to-rp__trace-detail">{{ $step->detail }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- ===== PROMPT ===== --}}
        <div class="to-rp__block">
            <h4 class="to-rp__block-title">{{ __('platform.resolver.prompt.title') }}</h4>
            <dl class="to-rp__kv">
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.winner') }}</dt>
                    <dd class="to-rp__kv-val">
                        <span class="to-badge to-badge--info">
                            <span class="to-badge__dot" aria-hidden="true"></span>
                            {{ __('platform.prompts.scope.'.$preview->winningPromptLevel) }}
                        </span>
                        @if($preview->winningPromptLevel === \App\Models\Prompt::SCOPE_GLOBAL)
                            <span class="to-rp__floor">{{ __('platform.resolver.fellthrough') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.prompt.id') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">{{ $preview->winningPromptId }}</dd>
                </div>
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.prompt.version') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">v{{ $preview->winningPromptVersion }}</dd>
                </div>
            </dl>
            <ol class="to-rp__trace">
                @foreach($preview->promptTrace->steps as $step)
                    <li class="to-rp__trace-step">
                        <span class="to-rp__trace-level">{{ __('platform.prompts.scope.'.$step->level) }}</span>
                        <span class="to-badge to-badge--{{ $this->outcomeTone($step->outcome) }}">
                            <span class="to-badge__dot" aria-hidden="true"></span>
                            {{ __('platform.resolver.outcome.'.$step->outcome) }}
                        </span>
                        <span class="to-rp__trace-detail">{{ $step->detail }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- ===== SAMPLE SUBSTITUTION (strtr, escaped, read-only) ===== --}}
        <div class="to-rp__block">
            <h4 class="to-rp__block-title">{{ __('platform.resolver.render.title') }}</h4>
            <p class="to-rp__sub">{{ __('platform.resolver.render.sub') }}</p>
            {{-- The substituted text is echoed via {{ }} (auto-escaped) — RCE-safe. --}}
            <pre class="to-rp__code">{{ $preview->renderUserPrompt($sampleVars) }}</pre>
        </div>

        {{-- ===== RESOLVED CONFIG ===== --}}
        <div class="to-rp__block">
            <h4 class="to-rp__block-title">{{ __('platform.resolver.config.title') }}</h4>
            <dl class="to-rp__kv">
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.config.quality') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">{{ $preview->imageQuality ?? '—' }}</dd>
                </div>
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.config.aspect') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">{{ $preview->aspectRatio ?? '—' }}</dd>
                </div>
                <div class="to-rp__kv-row">
                    <dt class="to-rp__kv-key">{{ __('platform.resolver.config.multiplier') }}</dt>
                    <dd class="to-rp__kv-val to-rp__mono">
                        {{ $preview->creditMultiplier !== null ? number_format($preview->creditMultiplier, 2).'×' : __('platform.resolver.config.multiplier_default') }}
                    </dd>
                </div>
            </dl>
        </div>
    @endif
</section>
