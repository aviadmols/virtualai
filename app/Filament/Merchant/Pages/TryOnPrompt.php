<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Ai\MentionTags;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\Site;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-shop TRY-ON PROMPT editor. The merchant tunes the wording that drives the try-on
 * image so the result stays faithful to their product, and can weave in the product's own
 * fields with {{tokens}} ({{materials}}, {{description}}, {{options}}, {{dimensions}}, …) —
 * filled at generation time from the real product data (ProductFacts), substituted with
 * strtr, NEVER Blade (RCE). Saving writes a scope=site Prompt for try_on_generation; the
 * AiOperationResolver then prefers it (site -> account -> product_type -> global). Leaving it
 * empty falls back to the platform default. Tenant-safe: the row is stamped with the shop's
 * OWN account_id + site_id and the resolver only ever reads a site prompt under its account.
 */
class TryOnPrompt extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.merchant.pages.try-on-prompt';

    private const OPERATION = AiOperation::KEY_TRY_ON_GENERATION;

    private const FIELD_PROMPT = 'user_prompt';

    private const NAV_LABEL = 'try_on_prompt.nav';

    private const TITLE = 'try_on_prompt.title';

    private const SAVED = 'try_on_prompt.saved';

    private const SAVE_FAILED = 'try_on_prompt.errors.save_failed';

    public ?int $siteId = null;

    public bool $hasSite = false;

    /** @var array<string,mixed> */
    public ?array $data = [];

    /** The platform default prompt, shown as the placeholder + the "reset" source. */
    public string $defaultPrompt = '';

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        $site = $tenant instanceof Site ? $tenant : Site::query()->orderBy('id')->first();

        $this->defaultPrompt = $this->globalDefaultPrompt();

        if ($site === null) {
            $this->form->fill([self::FIELD_PROMPT => '']);

            return;
        }

        $this->siteId = (int) $site->getKey();
        $this->hasSite = true;

        $current = Prompt::query()
            ->siteScoped((int) $site->account_id, (int) $site->getKey(), self::OPERATION)
            ->value('user_prompt');

        $this->form->fill([self::FIELD_PROMPT => (string) ($current ?? '')]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make(self::FIELD_PROMPT)
                    ->label(__('try_on_prompt.field.label'))
                    ->helperText(__('try_on_prompt.field.help'))
                    ->placeholder($this->defaultPrompt !== '' ? $this->defaultPrompt : null)
                    ->rows(10)
                    ->maxLength(4000)
                    ->autosize(),
            ])
            ->statePath('data');
    }

    /** The product-field tokens (raw names) the merchant may weave in. */
    public function tokens(): array
    {
        return MentionTags::PRODUCT_METAFIELD_TOKENS;
    }

    /**
     * The tokens as ready-to-type examples — each wrapped in the strtr braces (e.g.
     * "{{materials}}"). Built here, not in Blade, so the view never embeds literal echo
     * braces (which the Blade compiler mis-parses).
     *
     * @return array<int,string>
     */
    public function tokenExamples(): array
    {
        return array_map(static fn (string $token): string => '{{'.$token.'}}', $this->tokens());
    }

    public function save(): void
    {
        $site = $this->siteId !== null ? Site::query()->find($this->siteId) : null;

        if ($site === null) {
            return;
        }

        $prompt = trim((string) ($this->form->getState()[self::FIELD_PROMPT] ?? ''));

        try {
            Tenant::run($site->account_id, function () use ($site, $prompt): void {
                $existing = Prompt::query()
                    ->siteScoped((int) $site->account_id, (int) $site->getKey(), self::OPERATION)
                    ->first();

                // Empty -> deactivate the site override (fall back to the platform default).
                if ($prompt === '') {
                    $existing?->update(['is_active' => false]);

                    return;
                }

                if ($existing !== null) {
                    $existing->update(['user_prompt' => $prompt, 'is_active' => true]);

                    return;
                }

                Prompt::create([
                    'scope' => Prompt::SCOPE_SITE,
                    'operation_key' => self::OPERATION,
                    'account_id' => (int) $site->account_id,
                    'site_id' => (int) $site->getKey(),
                    'user_prompt' => $prompt,
                    'version' => 1,
                    'is_active' => true,
                ]);
            });

            Notification::make()->success()->title(__(self::SAVED))->send();
        } catch (\Throwable) {
            Notification::make()->danger()->title(__(self::SAVE_FAILED))->send();
        }
    }

    /** The seeded platform-global try-on prompt — the floor the resolver always has. */
    private function globalDefaultPrompt(): string
    {
        return (string) (Prompt::query()
            ->where('scope', Prompt::SCOPE_GLOBAL)
            ->where('operation_key', self::OPERATION)
            ->where('is_active', true)
            ->value('user_prompt') ?? '');
    }
}
