<?php

namespace App\Domain\Storyboard;

/**
 * StoryboardPromptComposer — the DETERMINISTIC frame-prompt builder.
 *
 * The scene breakdown writes only the per-frame SCENE (this beat's action, staging, location and
 * camera); the character identity/wardrobe blocks and the visual-bible style block are assembled
 * HERE, in code, from the locked bibles — so every frame carries the IDENTICAL character and style
 * text and no detail can drift, get re-worded or be dropped between frames by the LLM.
 *
 * Identity rule: a character bound to a @reference tag is anchored to the tag itself ("preserve
 * this exact person from @tag") plus the analysis-observed identity_lock — never a long re-invented
 * facial description that could contradict the reference image.
 */
final class StoryboardPromptComposer
{
    // === CONSTANTS ===
    private const SECTION_SEPARATOR = "\n\n";

    private const NEGATIVE_MAX_TERMS = 16;

    /**
     * The final image_prompt for one frame: scene beat + the frame's characters' locked blocks +
     * the global style block. Frames listing no known character get scene + style only.
     *
     * @param  array<string,mixed>  $frame  one scene-breakdown frame
     * @param  array<string,mixed>  $charactersBag  pipeline[characters]
     * @param  array<string,mixed>  $visualBible  pipeline[visual_bible]
     */
    public function compose(array $frame, array $charactersBag, array $visualBible): string
    {
        $scene = trim((string) ($frame['scene_prompt'] ?? $frame['image_prompt'] ?? ''));

        $sections = array_filter([
            $scene,
            $this->characterSection($frame, $charactersBag),
            $this->styleBlock($visualBible),
        ], static fn (string $s): bool => $s !== '');

        return implode(self::SECTION_SEPARATOR, $sections);
    }

    /**
     * The frame's negative prompt: the frame's own terms + the visual bible's reusable negative,
     * deduplicated and capped — so the bible's bans (e.g. "no shaky-cam blur") hold in EVERY frame.
     */
    public function negativePrompt(mixed $frameNegative, array $visualBible): ?string
    {
        $terms = [];

        foreach ([(string) ($frameNegative ?? ''), (string) ($visualBible['negative_prompt'] ?? '')] as $list) {
            foreach (explode(',', $list) as $term) {
                $term = trim($term);
                if ($term !== '' && ! in_array(mb_strtolower($term), array_map('mb_strtolower', $terms), true)) {
                    $terms[] = $term;
                }
            }
        }

        $terms = array_slice($terms, 0, self::NEGATIVE_MAX_TERMS);

        return $terms === [] ? null : implode(', ', $terms);
    }

    /** The locked character blocks for the characters this frame lists (bible order). */
    private function characterSection(array $frame, array $charactersBag): string
    {
        $inFrame = array_values(array_filter(array_map(
            static fn ($name): string => self::normalizeName((string) $name),
            is_array($frame['characters'] ?? null) ? $frame['characters'] : [],
        )));

        if ($inFrame === []) {
            return '';
        }

        $blocks = [];

        foreach ($this->characters($charactersBag) as $character) {
            if ($this->appearsIn($character, $inFrame)) {
                $blocks[] = $this->characterBlock($character);
            }
        }

        return implode("\n", array_filter($blocks));
    }

    /**
     * ONE character's locked block — identical text in every frame that features them.
     * Reference-bound: anchor to the @tag + the analysis-observed identity_lock. Original
     * (no tag): the bible's invented description. Both close with the FINAL story wardrobe.
     *
     * @param  array<string,mixed>  $character
     */
    private function characterBlock(array $character): string
    {
        $name = trim((string) ($character['name'] ?? ''));
        $tag = self::normalizeTag($character['tag'] ?? null);

        $parts = [];

        if ($tag !== null) {
            $parts[] = sprintf(
                '%s is the exact person in @%s — preserve that same face, apparent age, hair and body proportions; never invent or alter identity details.',
                $name,
                $tag,
            );

            $identityLock = trim((string) ($character['identity_lock'] ?? ''));
            if ($identityLock !== '') {
                $parts[] = $identityLock;
            }
        } else {
            $description = trim((string) ($character['description'] ?? ''));
            if ($description !== '') {
                $parts[] = $name.': '.$description;
            }
        }

        $wardrobe = trim((string) ($character['story_wardrobe'] ?? $character['outfit'] ?? ''));
        if ($wardrobe !== '') {
            $parts[] = sprintf('%s wears exactly this in every frame: %s.', $name, rtrim($wardrobe, '.'));
        }

        $prop = trim((string) ($character['signature_prop'] ?? ''));
        if ($prop !== '') {
            $parts[] = sprintf('%s carries: %s.', $name, rtrim($prop, '.'));
        }

        return implode(' ', $parts);
    }

    /** The bible's committed style, restated verbatim in every frame. */
    private function styleBlock(array $visualBible): string
    {
        $lines = array_filter([
            trim((string) ($visualBible['global_style'] ?? '')),
            self::labelled('Camera', $visualBible['camera'] ?? null),
            self::labelled('Lighting', $visualBible['lighting'] ?? null),
            self::labelled('Color palette', $visualBible['color_palette'] ?? null),
            self::labelled('Mood', $visualBible['mood'] ?? null),
        ], static fn (string $line): bool => $line !== '');

        return $lines === [] ? '' : 'Style, identical across all frames: '.implode(' ', $lines);
    }

    /** @return array<int,array<string,mixed>> */
    private function characters(array $charactersBag): array
    {
        $characters = $charactersBag['characters'] ?? null;

        return is_array($characters) ? array_values(array_filter($characters, 'is_array')) : [];
    }

    /** Does this bible character appear in the frame's character-name list? */
    private function appearsIn(array $character, array $inFrame): bool
    {
        $name = self::normalizeName((string) ($character['name'] ?? ''));

        if ($name === '') {
            return false;
        }

        foreach ($inFrame as $candidate) {
            // Tolerant match: "matan" ~ "matan the brother" in either direction.
            if ($candidate === $name || str_contains($candidate, $name) || str_contains($name, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function labelled(string $label, mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? '' : $label.': '.rtrim($value, '.').'.';
    }

    private static function normalizeName(string $name): string
    {
        return mb_strtolower(trim(ltrim(trim($name), '@')));
    }

    private static function normalizeTag(mixed $tag): ?string
    {
        $tag = trim(ltrim(trim((string) ($tag ?? '')), '@'));

        return $tag === '' ? null : $tag;
    }
}
