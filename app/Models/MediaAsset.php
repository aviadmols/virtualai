<?php

namespace App\Models;

use App\Domain\Media\MediaStorage;
use Illuminate\Database\Eloquent\Model;

/**
 * MediaAsset — a Super-Admin uploaded file (font / image / video / audio / file)
 * served at a STABLE public URL so it can be referenced anywhere in the system
 * (custom fonts in banners and panels, shared media, downloadable files).
 *
 * GLOBAL model (GlobalModels::ALLOW_LIST): platform-owned, no tenant, no charge.
 * The file lives under the public "media-assets/" prefix on the media disk; the
 * stored object is deleted with the row.
 */
class MediaAsset extends Model
{
    // === CONSTANTS ===
    public const KIND_FONT = 'font';
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_FILE = 'file';

    public const KINDS = [self::KIND_FONT, self::KIND_IMAGE, self::KIND_VIDEO, self::KIND_AUDIO, self::KIND_FILE];

    // Extension → kind. Doubles as the upload allow-list (anything else is refused).
    public const EXTENSION_KINDS = [
        'woff2' => self::KIND_FONT,
        'woff' => self::KIND_FONT,
        'ttf' => self::KIND_FONT,
        'otf' => self::KIND_FONT,
        'png' => self::KIND_IMAGE,
        'jpg' => self::KIND_IMAGE,
        'jpeg' => self::KIND_IMAGE,
        'webp' => self::KIND_IMAGE,
        'gif' => self::KIND_IMAGE,
        'svg' => self::KIND_IMAGE,
        'mp4' => self::KIND_VIDEO,
        'webm' => self::KIND_VIDEO,
        'mp3' => self::KIND_AUDIO,
        'wav' => self::KIND_AUDIO,
        'ogg' => self::KIND_AUDIO,
        'm4a' => self::KIND_AUDIO,
        'pdf' => self::KIND_FILE,
        'zip' => self::KIND_FILE,
    ];

    // Font extension → the @font-face src format() token.
    public const FONT_FORMATS = [
        'woff2' => 'woff2',
        'woff' => 'woff',
        'ttf' => 'truetype',
        'otf' => 'opentype',
    ];

    protected $fillable = [
        'name',
        'kind',
        'file_path',
        'original_filename',
        'size_bytes',
    ];

    protected static function booted(): void
    {
        // The stored object dies with the row — no orphaned files on the disk.
        static::deleting(static function (self $asset): void {
            if ($asset->file_path !== null && $asset->file_path !== '') {
                app(MediaStorage::class)->delete($asset->file_path);
            }
        });
    }

    /** The extension of the stored object (drives kind + the @font-face format). */
    public function extension(): string
    {
        return strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION));
    }

    /** The kind a stored path maps to (upload validation already enforced the list). */
    public static function kindForExtension(string $extension): string
    {
        return self::EXTENSION_KINDS[strtolower($extension)] ?? self::KIND_FILE;
    }

    /** The stable public URL of the stored object. */
    public function publicUrl(): ?string
    {
        return app(MediaStorage::class)->publicUrl($this->file_path);
    }

    /** A ready-to-paste @font-face block (fonts only). */
    public function fontFaceCss(): ?string
    {
        $format = self::FONT_FORMATS[$this->extension()] ?? null;
        $url = $this->publicUrl();

        if ($this->kind !== self::KIND_FONT || $format === null || $url === null) {
            return null;
        }

        $family = str_replace(["'", '\\'], '', (string) $this->name);

        return "@font-face {\n"
            ."    font-family: '{$family}';\n"
            ."    src: url('{$url}') format('{$format}');\n"
            ."    font-display: swap;\n"
            .'}';
    }
}
