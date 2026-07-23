<?php

namespace Tests\Feature\Filament\Platform;

use App\Filament\Platform\Resources\MediaAssetResource;
use App\Filament\Platform\Resources\MediaAssetResource\Pages\CreateMediaAsset;
use App\Filament\Platform\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\GlobalModels;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform media-assets library (super-admin): fonts + media uploaded once and served at a
 * STABLE public URL. Covers the global (non-tenant) membership, kind derivation, the ready-made
 * @font-face block, and that the create flow stamps kind + size and stores under the public prefix.
 */
class MediaAssetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trayon.media.disk', 'public');
        Storage::fake('public');

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_model_is_on_the_global_allow_list(): void
    {
        $this->assertTrue(GlobalModels::isGlobal(MediaAsset::class));
    }

    public function test_the_list_renders_for_a_super_admin(): void
    {
        Livewire::test(ListMediaAssets::class)->assertOk();
    }

    public function test_uploading_a_font_derives_the_kind_size_and_public_prefix(): void
    {
        $file = UploadedFile::fake()->createWithContent('Heebo.woff2', str_repeat('F', 2048));

        Livewire::test(CreateMediaAsset::class)
            ->fillForm([
                'name' => 'Heebo',
                'file_path' => $file,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = MediaAsset::query()->where('name', 'Heebo')->firstOrFail();

        $this->assertSame(MediaAsset::KIND_FONT, $asset->kind);
        $this->assertSame(2048, $asset->size_bytes);
        // The object lives under the public media-assets prefix (the only non-banner
        // family MediaController's public door serves).
        $this->assertStringStartsWith(MediaAssetResource::UPLOAD_DIRECTORY.'/', $asset->file_path);
        Storage::disk('public')->assertExists($asset->file_path);
    }

    public function test_a_font_hands_out_a_ready_to_paste_font_face_block(): void
    {
        $asset = MediaAsset::create([
            'name' => 'Heebo',
            'kind' => MediaAsset::KIND_FONT,
            'file_path' => 'media-assets/heebo.woff2',
            'original_filename' => 'Heebo.woff2',
            'size_bytes' => 2048,
        ]);

        $css = $asset->fontFaceCss();

        $this->assertNotNull($css);
        $this->assertStringContainsString("font-family: 'Heebo'", $css);
        $this->assertStringContainsString("format('woff2')", $css);
        $this->assertStringContainsString('media-assets/heebo.woff2', $css);
    }

    public function test_a_non_font_asset_has_no_font_face_block(): void
    {
        $asset = MediaAsset::create([
            'name' => 'Hero clip',
            'kind' => MediaAsset::KIND_VIDEO,
            'file_path' => 'media-assets/hero.mp4',
            'size_bytes' => 4096,
        ]);

        $this->assertNull($asset->fontFaceCss());
    }

    public function test_deleting_the_row_removes_the_stored_object(): void
    {
        Storage::disk('public')->put('media-assets/gone.png', 'BYTES');

        $asset = MediaAsset::create([
            'name' => 'Gone',
            'kind' => MediaAsset::KIND_IMAGE,
            'file_path' => 'media-assets/gone.png',
            'size_bytes' => 5,
        ]);

        $asset->delete();

        Storage::disk('public')->assertMissing('media-assets/gone.png');
    }

    public function test_the_kind_map_covers_the_upload_allow_list(): void
    {
        $this->assertSame(MediaAsset::KIND_FONT, MediaAsset::kindForExtension('ttf'));
        $this->assertSame(MediaAsset::KIND_IMAGE, MediaAsset::kindForExtension('PNG'));
        $this->assertSame(MediaAsset::KIND_AUDIO, MediaAsset::kindForExtension('mp3'));
        // Anything off the list falls back to a plain file (the FileUpload rule blocks it upstream).
        $this->assertSame(MediaAsset::KIND_FILE, MediaAsset::kindForExtension('xyz'));
    }
}
