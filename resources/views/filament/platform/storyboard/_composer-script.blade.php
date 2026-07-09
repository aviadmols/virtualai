{{--
    sbComposer — a contenteditable prompt editor shared by the Builder frame editor and the
    project form's Story-idea field. It renders @tag references as inline PILLS (thumbnail + name),
    offers a floating "IMAGES" picker (type @ → arrow/enter select), and serializes back to plain
    "@tag" text bound to a Livewire/Filament state path via $wire.$entangle (Filament's own idiom).

    Config:
      statePath  — the entangled state path (e.g. 'editPrompt' or 'data.story_idea'). Required.
      tags       — a static [{tag,url}] list (Builder). Used when assetsPath is absent.
      assetsPath — an entangled repeater path (e.g. 'data.assets'); the tag list + gallery are
                   derived LIVE from it, with thumbnails resolved via $wire.getStoryboardAssetUrls().
--}}
<script>
    (function () {
        const TAG_RE = /@([\p{L}\p{N}_]*)$/u;

        const register = () => {
            if (! window.Alpine || window.Alpine.__sbComposerRegistered) return;
            window.Alpine.__sbComposerRegistered = true;

            window.Alpine.data('sbComposer', (config) => ({
                staticTags: (config && config.tags) || [],
                statePath: (config && config.statePath) || null,
                assetsPath: (config && config.assetsPath) || null,
                assetsState: null,
                urls: {},
                show: false,
                items: [],
                active: 0,

                init() {
                    // assetsPath is a READ-ONLY reactive source (the uploaded reference pool) — entangle
                    // is fine here. The writable statePath is synced with $wire.set (below), NOT entangle:
                    // reassigning an entangled property replaces the proxy and silently drops the write.
                    if (this.assetsPath) {
                        this.assetsState = this.$wire.$entangle(this.assetsPath);
                        if (typeof this.$wire.getStoryboardAssetUrls === 'function') {
                            this.$wire.getStoryboardAssetUrls().then((map) => {
                                this.urls = map || {};
                                // Refresh initial pills with thumbnails once URLs arrive (unless typing).
                                if (document.activeElement !== this.$refs.editor) {
                                    this.renderText(this.serialize());
                                }
                            });
                        }
                    }
                    this.renderText(this.statePath ? (this.$wire.get(this.statePath) || '') : '');
                },

                // The reference tags: LIVE from the uploaded pool (auto-numbered @image1..@imageN, the
                // number IS the tag), else the static list passed in (Builder frame editor).
                get tags() {
                    if (! this.assetsPath) return this.staticTags;
                    const s = this.assetsState;
                    const rows = (Array.isArray(s) ? s : Object.values(s || {})).filter(Boolean);

                    return rows.map((row, i) => {
                        const tag = 'image' + (i + 1);
                        return { tag, url: this.urls[tag] || null };
                    });
                },

                // --- pill element (thumbnail + @label, contenteditable=false, click to remove) ---
                pill(t) {
                    const span = document.createElement('span');
                    span.className = 'to-sb-pill';
                    span.contentEditable = 'false';
                    span.dataset.tag = t.tag;
                    span.title = '@' + t.tag;
                    if (t.url) {
                        const img = document.createElement('img');
                        img.className = 'to-sb-pill__img';
                        img.src = t.url;
                        img.alt = '';
                        span.appendChild(img);
                    }
                    const label = document.createElement('span');
                    label.className = 'to-sb-pill__label';
                    label.textContent = '@' + t.tag;
                    span.appendChild(label);
                    span.addEventListener('click', (e) => {
                        e.preventDefault();
                        span.remove();
                        this.sync();
                        this.focusEditor();
                    });
                    return span;
                },

                // --- render source text -> text nodes + pills (empty stays empty for the placeholder) ---
                renderText(text) {
                    const el = this.$refs.editor;
                    el.innerHTML = '';
                    if (! text) return;
                    const re = /@([\p{L}\p{N}_]+)/gu;
                    let last = 0, m;
                    while ((m = re.exec(text)) !== null) {
                        if (m.index > last) el.appendChild(document.createTextNode(text.slice(last, m.index)));
                        const known = this.tags.find((t) => t.tag === m[1]);
                        el.appendChild(known ? this.pill(known) : document.createTextNode(m[0]));
                        last = re.lastIndex;
                    }
                    if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
                    if (el.lastChild && el.lastChild.nodeType === Node.ELEMENT_NODE) {
                        el.appendChild(document.createTextNode(' '));
                    }
                },

                // --- contenteditable -> plain "@tag" text ---
                serialize(root) {
                    root = root || this.$refs.editor;
                    let out = '';
                    root.childNodes.forEach((node) => {
                        if (node.nodeType === Node.TEXT_NODE) {
                            out += node.nodeValue;
                        } else if (node.nodeType === Node.ELEMENT_NODE) {
                            if (node.dataset && node.dataset.tag) {
                                out += '@' + node.dataset.tag;
                            } else if (node.tagName === 'BR') {
                                out += '\n';
                            } else if (node.tagName === 'DIV' || node.tagName === 'P') {
                                if (out && ! out.endsWith('\n')) out += '\n';
                                out += this.serialize(node);
                            } else {
                                out += this.serialize(node);
                            }
                        }
                    });
                    return out;
                },

                sync() {
                    // Deferred set: updates the Livewire property now, sent with the next request
                    // (Save / Generate) — so validation sees the story text and no round-trip fights the caret.
                    if (this.statePath) {
                        this.$wire.set(this.statePath, this.serialize(), false);
                    }
                },

                onInput() {
                    this.detect();
                    this.sync();
                },

                detect() {
                    const sel = window.getSelection();
                    if (! sel || ! sel.rangeCount) { this.show = false; return; }
                    const range = sel.getRangeAt(0);
                    const node = range.startContainer;
                    if (node.nodeType !== Node.TEXT_NODE) { this.show = false; return; }
                    const m = node.nodeValue.slice(0, range.startOffset).match(TAG_RE);
                    if (m) {
                        const q = m[1].toLowerCase();
                        this.items = this.tags.filter((t) => t.tag.toLowerCase().includes(q));
                        this.active = 0;
                        this.show = this.items.length > 0;
                    } else {
                        this.show = false;
                    }
                },

                onKeydown(e) {
                    if (! this.show) return;
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.active = (this.active + 1) % this.items.length; }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = (this.active - 1 + this.items.length) % this.items.length; }
                    else if (e.key === 'Enter') { e.preventDefault(); this.pick(this.items[this.active]); }
                    else if (e.key === 'Escape') { this.show = false; }
                },

                pick(t) {
                    if (! t) return;
                    const sel = window.getSelection();
                    if (! sel || ! sel.rangeCount) { this.appendPill(t); return; }
                    const range = sel.getRangeAt(0);
                    const node = range.startContainer;
                    if (node.nodeType !== Node.TEXT_NODE) { this.appendPill(t); return; }
                    const text = node.nodeValue;
                    const m = text.slice(0, range.startOffset).match(TAG_RE);
                    const start = m ? range.startOffset - m[0].length : range.startOffset;
                    const tail = text.slice(range.startOffset);
                    node.nodeValue = text.slice(0, start);
                    const parent = node.parentNode;
                    const tailNode = document.createTextNode(tail);
                    const space = document.createTextNode(' ');
                    const pill = this.pill(t);
                    parent.insertBefore(tailNode, node.nextSibling);
                    parent.insertBefore(space, node.nextSibling);
                    parent.insertBefore(pill, node.nextSibling);
                    this.caretAfter(space);
                    this.show = false;
                    this.sync();
                },

                insertTag(tag) {
                    const t = this.tags.find((x) => x.tag === tag);
                    if (t) this.appendPill(t);
                },

                appendPill(t) {
                    const el = this.$refs.editor;
                    const space = document.createTextNode(' ');
                    el.appendChild(this.pill(t));
                    el.appendChild(space);
                    this.focusEditor();
                    this.caretAfter(space);
                    this.sync();
                },

                caretAfter(node) {
                    const sel = window.getSelection();
                    const r = document.createRange();
                    r.setStart(node, node.length);
                    r.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(r);
                },

                clearAll() {
                    this.$refs.editor.innerHTML = '';
                    this.show = false;
                    this.sync();
                    this.focusEditor();
                },

                focusEditor() {
                    this.$refs.editor.focus();
                },
            }));
        };

        if (window.Alpine) register();
        else document.addEventListener('alpine:init', register);
    })();
</script>
