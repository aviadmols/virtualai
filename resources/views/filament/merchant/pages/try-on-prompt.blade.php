{{--
    Per-shop try-on prompt editor. The merchant tunes the wording that drives the try-on image and
    weaves in the product's own fields with {{tokens}} (filled at generation time from ProductFacts,
    substituted with strtr — never Blade). Picking a product reveals ITS real metafield tokens.

    Alpine adds two conveniences over the Filament textarea: click a chip to insert its token at the
    caret, and typing "@" opens a token menu. Both dispatch a native input event so wire:model stays
    in sync. TOKENS: token-picker.css (.to-token*). No inline CSS.
--}}
<x-filament-panels::page>
    @if ($hasSite)
        <div
            class="to-prompt-editor"
            x-data="tryOnPromptEditor()"
            x-on:keydown.window.escape="closeMenu()"
            wire:key="ton-prompt-editor"
        >
            <form wire:submit="save" class="fi-form grid gap-y-6">
                {{ $this->form }}

                {{-- The product fields the prompt may reference. Click a chip to insert its token,
                     or type "@" in the prompt to pick from the same list. --}}
                <x-filament::section>
                    <x-slot:heading>{{ __('try_on_prompt.tokens.title') }}</x-slot:heading>
                    <x-slot:description>{{ __('try_on_prompt.tokens.sub') }}</x-slot:description>

                    @php($groups = $this->tokenGroups())

                    <div class="to-token-group">
                        <p class="to-token-group__label">{{ __('try_on_prompt.tokens.fixed') }}</p>
                        <div class="to-token-chips">
                            @foreach ($groups['fixed'] as $chip)
                                <button type="button" class="to-token-chip"
                                        x-on:click="insert(@js($chip['token']))">
                                    <span class="to-token-chip__name">{{ $chip['display'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if (! empty($groups['metafields']))
                        <div class="to-token-group">
                            <p class="to-token-group__label">{{ __('try_on_prompt.tokens.metafields') }}</p>
                            <div class="to-token-chips">
                                @foreach ($groups['metafields'] as $chip)
                                    <button type="button" class="to-token-chip to-token-chip--mf"
                                            x-on:click="insert(@js($chip['token']))"
                                            :title="@js($chip['label'])">
                                        <span class="to-token-chip__name">{{ $chip['display'] }}</span>
                                        <span class="to-token-chip__value">{{ \Illuminate\Support\Str::limit($chip['value'], 36) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @elseif (($this->data['product_id'] ?? null))
                        <p class="to-token-empty">{{ __('try_on_prompt.tokens.none') }}</p>
                    @endif
                </x-filament::section>

                <div class="flex justify-end">
                    <x-filament::button type="submit">
                        {{ __('try_on_prompt.save') }}
                    </x-filament::button>
                </div>
            </form>

            {{-- The "@" token menu — the SAME tokens, shown on demand next to the caret. --}}
            <div class="to-token-menu" x-show="menuOpen" x-cloak
                 x-on:click.outside="closeMenu()"
                 role="listbox" aria-label="{{ __('try_on_prompt.tokens.title') }}">
                @foreach ($groups['fixed'] as $chip)
                    <button type="button" class="to-token-menu__item" x-on:click="insert(@js($chip['token']))">
                        {{ $chip['display'] }}
                    </button>
                @endforeach
                @foreach ($groups['metafields'] as $chip)
                    <button type="button" class="to-token-menu__item" x-on:click="insert(@js($chip['token']))">
                        <span class="to-token-menu__name">{{ $chip['display'] }}</span>
                        <span class="to-token-menu__value">{{ \Illuminate\Support\Str::limit($chip['value'], 36) }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <script>
            function tryOnPromptEditor() {
                return {
                    menuOpen: false,
                    atIndex: -1,
                    // The prompt textarea lives inside the Filament form under this root.
                    textarea() {
                        return this.$root.querySelector('textarea');
                    },
                    init() {
                        const ta = this.textarea();
                        if (!ta) return;
                        // Typing "@" opens the token menu; the insert replaces from the "@".
                        ta.addEventListener('keydown', (e) => {
                            if (e.key === '@') {
                                this.atIndex = ta.selectionStart;
                                this.menuOpen = true;
                            }
                        });
                    },
                    closeMenu() {
                        this.menuOpen = false;
                        this.atIndex = -1;
                    },
                    // Insert {{token}} — replacing from the "@" when the menu drove it, else at the caret.
                    insert(token) {
                        const ta = this.textarea();
                        if (!ta) return;
                        const text = '{{' + token + '}}';
                        const from = this.atIndex >= 0 ? this.atIndex : ta.selectionStart;
                        const to = ta.selectionEnd;
                        ta.value = ta.value.slice(0, from) + text + ta.value.slice(to);
                        const caret = from + text.length;
                        ta.setSelectionRange(caret, caret);
                        // Sync wire:model, then return focus to the prompt.
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                        ta.focus();
                        this.closeMenu();
                    },
                };
            }
        </script>
    @else
        <x-filament::section>
            <p>{{ __('try_on_prompt.no_site') }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
