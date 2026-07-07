<?php

namespace Tests\Feature\Filament\Merchant;

use App\Domain\Banners\BannerRules;
use App\Domain\Banners\BannerService;
use App\Domain\Generation\GenerateBannerJob;
use App\Filament\Merchant\Resources\BannerResource;
use App\Filament\Merchant\Resources\BannerResource\Pages\CreateBanner;
use App\Filament\Merchant\Resources\BannerResource\Pages\EditBanner;
use App\Filament\Merchant\Resources\BannerResource\Pages\ListBanners;
use App\Filament\Merchant\Widgets\BannerCandidatesWidget;
use App\Models\Account;
use App\Models\Banner;
use App\Models\BannerAsset;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merchant Banners resource — list + create draft + the editor (generate candidate, select
 * artwork, activate). Proves the tenant-narrowed list, that every write routes through the
 * validated writer, that generate dispatches the money-path job, that selecting a candidate
 * copies its artwork, that activation needs artwork, and that a foreign shop's banners are invisible.
 */
class BannerResourcePageTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->site = Site::factory()->forAccount($this->account)->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs(User::factory()->forAccount($this->account)->create());
        Filament::setTenant($this->site);
    }

    private function draft(string $name = 'Summer Sale'): Banner
    {
        return Tenant::run($this->account, fn () => app(BannerService::class)->createDraft($this->site, $name));
    }

    private function succeededAsset(Banner $banner): BannerAsset
    {
        return Tenant::run($this->account, fn () => BannerAsset::factory()->forBanner($banner)->create([
            'status' => BannerAsset::STATUS_SUCCEEDED,
            'image_path' => 'accounts/1/sites/1/banners/9/banner-xyz.png',
            'image_mime' => 'image/png',
            'image_width' => 1200,
            'image_height' => 675,
        ]));
    }

    public function test_list_renders_for_the_owner(): void
    {
        $this->draft();

        Tenant::run($this->account, function (): void {
            Livewire::test(ListBanners::class)->assertOk();
        });
    }

    public function test_create_makes_a_draft_through_the_service(): void
    {
        Tenant::run($this->account, function (): void {
            Livewire::test(CreateBanner::class)
                ->fillForm(['name' => 'Winter Promo'])
                ->call('create')
                ->assertHasNoFormErrors();
        });

        $banner = Tenant::run($this->account, fn () => Banner::query()->where('name', 'Winter Promo')->first());
        $this->assertNotNull($banner);
        $this->assertSame(Banner::STATUS_DRAFT, $banner->status);
        $this->assertSame((int) $this->site->id, (int) $banner->site_id);
    }

    public function test_generate_action_dispatches_the_money_path_job_and_creates_a_pending_asset(): void
    {
        Bus::fake([GenerateBannerJob::class]);
        $banner = $this->draft();

        Tenant::run($this->account, function () use ($banner): void {
            Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
                ->callAction('generate', ['brief' => 'A bold red summer sale banner']);
        });

        Bus::assertDispatched(GenerateBannerJob::class);
        $asset = Tenant::run($this->account, fn () => BannerAsset::query()->where('banner_id', $banner->id)->first());
        $this->assertNotNull($asset);
        $this->assertSame(BannerAsset::STATUS_PENDING, $asset->status);
        $this->assertSame('A bold red summer sale banner', $asset->brief);
    }

    public function test_selecting_a_candidate_in_the_widget_copies_its_artwork(): void
    {
        $banner = $this->draft();
        $asset = $this->succeededAsset($banner);

        // The live candidate gallery owns selection now (no reload / no save needed): clicking
        // "Use this image" copies the artwork and tells the editor to refresh.
        Tenant::run($this->account, function () use ($banner, $asset): void {
            Livewire::test(BannerCandidatesWidget::class, ['record' => $banner])
                ->call('useAsset', $asset->id)
                ->assertDispatched('banner-artwork-selected');
        });

        $fresh = Tenant::run($this->account, fn () => Banner::query()->find($banner->id));
        $this->assertSame($asset->id, $fresh->selected_asset_id);
        $this->assertSame($asset->image_path, $fresh->image_path);
        $this->assertTrue($fresh->hasArtwork());
    }

    public function test_widget_polls_only_while_a_candidate_is_generating(): void
    {
        $banner = $this->draft();
        $asset = Tenant::run($this->account, fn () => BannerAsset::factory()->forBanner($banner)->create([
            'status' => BannerAsset::STATUS_PENDING,
        ]));

        // A candidate is still in flight → the widget advertises a poll interval.
        Tenant::run($this->account, function () use ($banner): void {
            $working = Livewire::test(BannerCandidatesWidget::class, ['record' => $banner]);
            $this->assertTrue($working->instance()->isWorking());
            $this->assertNotNull($working->instance()->pollInterval());
        });

        // Once it reaches a terminal state, polling stops (no interval).
        Tenant::run($this->account, fn () => $asset->forceFill([
            'status' => BannerAsset::STATUS_SUCCEEDED, 'image_path' => 'accounts/1/sites/1/banners/9/x.png',
        ])->save());

        Tenant::run($this->account, function () use ($banner): void {
            $idle = Livewire::test(BannerCandidatesWidget::class, ['record' => $banner]);
            $this->assertFalse($idle->instance()->isWorking());
            $this->assertNull($idle->instance()->pollInterval());
        });
    }

    public function test_widget_surfaces_a_failed_candidates_reason(): void
    {
        $banner = $this->draft();
        Tenant::run($this->account, fn () => BannerAsset::factory()->forBanner($banner)->create([
            'status' => BannerAsset::STATUS_FAILED,
            'failure_code' => 'ai_call_failed',
            'meta' => [BannerAsset::META_FAILURE_MESSAGE => 'OpenRouter model is not in the live catalog'],
        ]));

        Tenant::run($this->account, function () use ($banner): void {
            Livewire::test(BannerCandidatesWidget::class, ['record' => $banner])
                ->assertSee('OpenRouter model is not in the live catalog');
        });
    }

    public function test_widget_retry_dispatches_a_fresh_generation_from_the_stored_brief(): void
    {
        Bus::fake([GenerateBannerJob::class]);
        $banner = $this->draft();
        $failed = Tenant::run($this->account, fn () => BannerAsset::factory()->forBanner($banner)->create([
            'status' => BannerAsset::STATUS_FAILED,
            'brief' => 'A bold red summer sale banner',
        ]));

        Tenant::run($this->account, function () use ($banner, $failed): void {
            Livewire::test(BannerCandidatesWidget::class, ['record' => $banner])
                ->call('retry', $failed->id);
        });

        Bus::assertDispatched(GenerateBannerJob::class);
        // A fresh pending candidate was created for the retry.
        $this->assertSame(2, Tenant::run($this->account, fn () => BannerAsset::query()->where('banner_id', $banner->id)->count()));
    }

    public function test_activate_needs_artwork_then_succeeds_once_selected(): void
    {
        $banner = $this->draft();

        // No artwork yet -> the activate action is rejected (soft), banner stays draft.
        Tenant::run($this->account, function () use ($banner): void {
            Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
                ->callAction('activate');
        });
        $this->assertSame(Banner::STATUS_DRAFT, Tenant::run($this->account, fn () => Banner::query()->find($banner->id))->status);

        // Select artwork, then activate succeeds.
        $asset = $this->succeededAsset($banner);
        Tenant::run($this->account, fn () => app(BannerService::class)->selectAsset($banner, $asset));

        Tenant::run($this->account, function () use ($banner): void {
            Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
                ->callAction('activate');
        });
        $this->assertSame(Banner::STATUS_ACTIVE, Tenant::run($this->account, fn () => Banner::query()->find($banner->id))->status);
    }

    public function test_saving_persists_targeting_rules(): void
    {
        $banner = $this->draft();

        Tenant::run($this->account, function () use ($banner): void {
            Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
                ->fillForm([
                    'name' => $banner->name,
                    'composition' => Banner::COMPOSITION_IMAGE,
                    'rules' => [
                        'audience' => BannerRules::AUDIENCE_CLUB_MEMBERS,
                        'pages' => ['context' => BannerRules::PAGE_PDP, 'url_contains' => '/x'],
                        'frequency' => ['max_per_session' => 2],
                        'locales' => ['he'],
                    ],
                ])
                ->call('save')
                ->assertHasNoFormErrors();
        });

        $fresh = Tenant::run($this->account, fn () => Banner::query()->find($banner->id));
        $this->assertSame(BannerRules::AUDIENCE_CLUB_MEMBERS, $fresh->rules['audience']);
        $this->assertSame(BannerRules::PAGE_PDP, $fresh->rules['pages']['context']);
        $this->assertSame(2, $fresh->rules['frequency']['max_per_session']);
        $this->assertSame(['he'], $fresh->rules['locales']);
    }

    public function test_a_foreign_shops_banners_are_invisible(): void
    {
        $ours = $this->draft('Ours');

        $otherAccount = Account::factory()->create();
        $otherSite = Site::factory()->forAccount($otherAccount)->create();
        $otherBanner = Tenant::run($otherAccount, fn () => app(BannerService::class)->createDraft($otherSite, 'Theirs'));

        Tenant::run($this->account, function () use ($ours, $otherBanner): void {
            $ids = BannerResource::getEloquentQuery()->pluck('id')->all();
            $this->assertContains($ours->id, $ids);
            $this->assertNotContains($otherBanner->id, $ids);
        });
    }
}
