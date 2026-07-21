<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Ai\MentionTags;
use App\Domain\Generation\ProductFacts;
use App\Models\AiOperation;
use App\Models\Product;
use App\Models\Prompt;
use App\Models\Site;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.merchant.pages.try-on-prompt';

    private const OPERATION = AiOperation::KEY_TRY_ON_GENERATION;

    private const FIELD_PROMPT = 'user_prompt';

    // The product-preview picker (drives which metafield tokens the chips offer).
    private const FIELD_PRODUCT = 'product_id';

    // How many products the preview picker offers.
    private const PRODUCT_LIMIT = 50;

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
            // No shop yet: still show the editable platform default as the starting text.
            $this->form->fill([self::FIELD_PROMPT => $this->defaultPrompt]);

            return;
        }

        $this->siteId = (int) $site->getKey();
        $this->hasSite = true;

        $current = Prompt::query()
            ->siteScoped((int) $site->account_id, (int) $site->getKey(), self::OPERATION)
            ->value('user_prompt');

        // Pre-fill the merchant's OWN prompt when they have one; otherwise seed the box with the
        // real platform-default TEXT (editable), not just a greyed-out placeholder — so they can
        // tweak the working prompt instead of starting from a blank field. Clearing it and saving
        // still falls back to the platform default.
        $this->form->fill([self::FIELD_PROMPT => (string) ($current ?: $this->defaultPrompt)]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Pick a product to preview + insert ITS real metafield tokens (optional). The list
                // of chips below reacts to this choice; the prompt itself is product-agnostic.
                Select::make(self::FIELD_PRODUCT)
                    ->label(__('try_on_prompt.field.product'))
                    ->helperText(__('try_on_prompt.field.product_help'))
                    ->options(fn (): array => $this->productOptions())
                    ->searchable()
                    ->live(),

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

    /** The shop's ACTIVE products (account-scoped) for the token-preview picker. @return array<int,string> */
    public function productOptions(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Product::query()
            ->where('site_id', $this->siteId)
            ->active()
            ->orderBy('name')
            ->limit(self::PRODUCT_LIMIT)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The tokens the merchant can weave in, in two groups: the FIXED product fields (always
     * available) and — when a product is picked — that product's real METAFIELDS with a live value
     * preview. Each token is {token, label, value} so the view can show what it resolves to.
     *
     * @return array{fixed: array<int,array{token:string,label:string,value:?string}>, metafields: array<int,array{token:string,label:string,value:string}>}
     */
    public function tokenGroups(): array
    {
        $fixed = array_map(
            // display carries the strtr-wrapped form; the view echoes it directly (Blade
            // mis-parses a literal {{ }} written inline).
            static fn (string $token): array => ['token' => $token, 'display' => '{{'.$token.'}}', 'label' => $token, 'value' => null],
            MentionTags::PRODUCT_METAFIELD_TOKENS,
        );

        $metafields = [];
        $productId = $this->data[self::FIELD_PRODUCT] ?? null;

        if ($this->siteId !== null && $productId !== null && $productId !== '') {
            $product = Product::query()
                ->where('site_id', $this->siteId)
                ->whereKey((int) $productId)
                ->first();

            if ($product !== null) {
                $metafields = array_map(
                    static fn (array $mf): array => $mf + ['display' => '{{'.$mf['token'].'}}'],
                    ProductFacts::availableMetafields($product),
                );
            }
        }

        return ['fixed' => $fixed, 'metafields' => $metafields];
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
