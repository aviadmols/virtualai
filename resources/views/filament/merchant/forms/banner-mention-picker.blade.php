{{--
    Banner brief @-product picker. Lets the merchant tag a product in the brief with @product_{id};
    the generation job (GenerateBannerJob) resolves the tag to the product's image (reference) + its
    facts, so the banner is built FROM that product.

    Two entry points into ONE searchable product menu: the "Tag a product" button, or typing "@"
    inside the brief. A live "Based on:" row shows the attached products (each removable). There is
    NO raw chip list — the menu is the single, searchable surface.

    The @product token + product list arrive via @js so Blade never mis-parses a literal @product.
    Alpine targets the sibling brief textarea (data-banner-brief) — one open generate modal at a time.
    TOKENS: token-picker.css (.to-token* / .to-mention-*). No inline CSS.
--}}
@php($items = collect($products)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all())

<div
    class="to-prompt-editor to-banner-mention"
    x-data="{
        products: @js($items),
        prefix: @js('@'.\App\Domain\Ai\MentionTags::PREFIX_PRODUCT),
        menuOpen: false,
        atIndex: -1,
        query: '',
        tags: [],
        ta: null,
        init() {
            this.ta = document.querySelector('textarea[data-banner-brief]');
            if (! this.ta) return;
            this.sync();
            this.ta.addEventListener('input', () => this.sync());
            this.ta.addEventListener('keydown', (e) => {
                if (e.key === '@') { this.atIndex = this.ta.selectionStart; this.openMenu(); }
            });
        },
        filtered() {
            const q = this.query.trim().toLowerCase();
            return q === '' ? this.products : this.products.filter((p) => p.name.toLowerCase().includes(q));
        },
        nameFor(id) {
            const p = this.products.find((x) => String(x.id) === String(id));
            return p ? p.name : null;
        },
        sync() {
            if (! this.ta) return;
            const re = new RegExp(this.prefix + '(\\d+)', 'g');
            const ids = [];
            let m;
            while ((m = re.exec(this.ta.value)) !== null) { if (! ids.includes(m[1])) ids.push(m[1]); }
            this.tags = ids.map((id) => ({ id, name: this.nameFor(id) })).filter((t) => t.name !== null);
        },
        insert(id) {
            if (! this.ta) return;
            const text = this.prefix + id;
            const from = this.atIndex >= 0 ? this.atIndex : this.ta.selectionStart;
            const to = this.ta.selectionEnd;
            this.ta.value = this.ta.value.slice(0, from) + text + this.ta.value.slice(to);
            const caret = from + text.length;
            this.ta.setSelectionRange(caret, caret);
            this.ta.dispatchEvent(new Event('input', { bubbles: true }));
            this.ta.focus();
            this.closeMenu();
            this.sync();
        },
        remove(id) {
            if (! this.ta) return;
            const re = new RegExp(this.prefix + id + '(\\s|$)', 'g');
            this.ta.value = this.ta.value.replace(re, '$1').replace(/\s+$/, '');
            this.ta.dispatchEvent(new Event('input', { bubbles: true }));
            this.sync();
        },
        openMenu() {
            this.query = '';
            this.menuOpen = true;
            this.$nextTick(() => this.$refs.search?.focus());
        },
        toggleMenu() { this.menuOpen ? this.closeMenu() : this.openMenu(); },
        closeMenu() { this.menuOpen = false; this.atIndex = -1; },
    }"
    x-on:keydown.window.escape="closeMenu()"
>
    @if (! empty($items))
        {{-- The tag toolbar: one explicit entry button + the "type @" hint. --}}
        <div class="to-mention-bar">
            <button type="button" class="to-mention-add" x-on:click="toggleMenu()">
                <x-filament::icon icon="heroicon-m-at-symbol" class="to-mention-add__icon" />
                <span>{{ __('banners.generate.mention_add') }}</span>
            </button>
            <span class="to-mention-bar__hint">{{ __('banners.generate.mention_help') }}</span>
        </div>
    @else
        <p class="to-token-empty">{{ __('banners.generate.mention_empty') }}</p>
    @endif

    {{-- Live "Based on:" tags parsed out of the brief text. --}}
    <div class="to-mention-based" x-show="tags.length > 0" x-cloak>
        <span class="to-token-group__label">{{ __('banners.generate.mention_based_on') }}</span>
        <template x-for="tag in tags" :key="tag.id">
            <span class="to-mention-tag">
                <span x-text="tag.name"></span>
                <button type="button" class="to-mention-tag__remove"
                        x-on:click="remove(tag.id)"
                        :aria-label="@js(__('banners.generate.mention_remove'))">&times;</button>
            </span>
        </template>
    </div>

    {{-- The searchable product menu — opened by the button or by typing "@" in the brief. --}}
    <div class="to-token-menu to-mention-menu" x-show="menuOpen" x-cloak
         x-on:click.outside="closeMenu()"
         role="listbox" aria-label="{{ __('banners.generate.mention_products') }}">
        <input
            type="search"
            class="to-mention-menu__search"
            x-ref="search"
            x-model="query"
            placeholder="{{ __('banners.generate.mention_search') }}"
        />
        <template x-for="p in filtered()" :key="p.id">
            <button type="button" class="to-token-menu__item" x-on:click="insert(p.id)">
                <span class="to-token-menu__name" x-text="p.name"></span>
            </button>
        </template>
        <p class="to-token-empty" x-show="filtered().length === 0">{{ __('banners.generate.mention_no_match') }}</p>
    </div>
</div>
