<?php

namespace App\Domain\Shopify\Media;

use App\Models\ProductAsset;
use InvalidArgumentException;

/**
 * MediaPlacement — WHERE an approved image goes in the product's live Shopify gallery.
 *
 *   append      end of the gallery. NOTHING existing is touched -> not destructive.
 *   position N  a specific 1-based slot (N = 1 is the main/featured image). Every image at or
 *              after N shifts down -> the gallery ORDER (and possibly the featured image)
 *              changes -> DESTRUCTIVE.
 *   replace     an existing image is swapped out and then DELETED -> DESTRUCTIVE, and
 *              irreversible without our own byte-level snapshot.
 *
 * `isDestructive()` is the single predicate the pusher gates the MANDATORY original-gallery
 * snapshot on. It is deliberately conservative: a reorder is destructive too, because a
 * merchant who loses their featured image has lost something real even though no bytes died.
 */
final readonly class MediaPlacement
{
    // === CONSTANTS ===
    public const MODE_APPEND = ProductAsset::PLACEMENT_APPEND;

    public const MODE_POSITION = ProductAsset::PLACEMENT_POSITION;

    public const MODE_REPLACE = ProductAsset::PLACEMENT_REPLACE;

    public const MODES = [self::MODE_APPEND, self::MODE_POSITION, self::MODE_REPLACE];

    // The first, merchant-facing slot. Shopify's MoveInput is zero-based; we are not.
    public const FIRST_POSITION = 1;

    private const MSG_UNKNOWN_MODE = 'Unknown media placement mode "%s".';

    private const MSG_POSITION_REQUIRED = 'A "position" placement needs a 1-based position.';

    private const MSG_REPLACE_REQUIRED = 'A "replace" placement needs the media id it replaces.';

    private function __construct(
        public string $mode,
        public ?int $position = null,
        public ?string $replaceMediaId = null,
    ) {}

    /** The safe default: nothing existing is touched. */
    public static function append(): self
    {
        return new self(self::MODE_APPEND);
    }

    /** Insert at a specific 1-based slot (1 = the main image). */
    public static function position(int $position): self
    {
        if ($position < self::FIRST_POSITION) {
            throw new InvalidArgumentException(self::MSG_POSITION_REQUIRED);
        }

        return new self(self::MODE_POSITION, $position);
    }

    /** Swap out a specific existing media (deleted ONLY once the replacement is READY). */
    public static function replace(string $mediaId): self
    {
        if ($mediaId === '') {
            throw new InvalidArgumentException(self::MSG_REPLACE_REQUIRED);
        }

        return new self(self::MODE_REPLACE, null, $mediaId);
    }

    /** Rebuild from the merchant's form input (typed; an unknown mode is refused loudly). */
    public static function fromInput(string $mode, ?int $position = null, ?string $mediaId = null): self
    {
        return match ($mode) {
            self::MODE_APPEND => self::append(),
            self::MODE_POSITION => self::position((int) $position),
            self::MODE_REPLACE => self::replace((string) $mediaId),
            default => throw new InvalidArgumentException(sprintf(self::MSG_UNKNOWN_MODE, $mode)),
        };
    }

    /** Rebuild from the PERSISTED intent on the asset (what a RE-PUSH retries). */
    public static function fromAsset(ProductAsset $asset): self
    {
        return self::fromInput(
            (string) ($asset->push_placement ?? self::MODE_APPEND),
            $asset->push_position,
            $asset->push_replaced_media_id,
        );
    }

    /** Jobs serialize scalars; this is the wire shape. @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'position' => $this->position,
            'replace_media_id' => $this->replaceMediaId,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return self::fromInput(
            (string) ($data['mode'] ?? self::MODE_APPEND),
            isset($data['position']) ? (int) $data['position'] : null,
            isset($data['replace_media_id']) ? (string) $data['replace_media_id'] : null,
        );
    }

    /**
     * TRUE when applying this placement changes something the merchant already had — an existing
     * image's slot, the featured image, or the image itself. The snapshot gate hangs on this.
     */
    public function isDestructive(): bool
    {
        return $this->mode !== self::MODE_APPEND;
    }

    public function isReplace(): bool
    {
        return $this->mode === self::MODE_REPLACE;
    }

    public function isPositioned(): bool
    {
        return $this->mode === self::MODE_POSITION;
    }
}
