<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Shopify\Auth\ShopifyInstaller;
use App\Domain\Shopify\Auth\ShopifyOAuth;
use App\Filament\Merchant\Concerns\ResolvesShopAccount;
use App\Models\ShopifyConnection;
use App\Models\ShopifyWebhookReceipt;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Shopify store (Phase 2) — the merchant's connect / disconnect screen and the health of the
 * connection.
 *
 * CONNECT starts the `connect_existing_site` OAuth flow: the merchant types their myshopify.com
 * domain and is redirected to the OAuth start route, which mints the signed state and hands off
 * to Shopify's grant screen. This page therefore issues NO token, writes NO connection and holds
 * NO secret — ShopifyInstaller (behind the callback) is the single persist path.
 *
 * DISCONNECT is the one write here: ShopifyInstaller::disconnect — a guarded transition to
 * `uninstalled` that wipes the encrypted credentials. Products, try-ons and the gallery survive
 * (a re-connect re-activates the SAME connection row).
 *
 * Tenant-safety: the store is the bound shop tenant (Filament::getTenant()), and the connection is
 * read through its BelongsToAccount relation — no manual account filter, no withoutGlobalScopes().
 * Webhook receipts are PLATFORM rows (pre-bind, no account_id), so the health counters are keyed
 * strictly by THIS connection's shop_domain — the only routing fact that identifies the store.
 */
class ShopifyStore extends Page
{
    use ResolvesShopAccount;

    // === CONSTANTS ===
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.merchant.pages.shopify-store';

    // The OAuth start route + its query contract (OAuthController::Q_SHOP / Q_SITE).
    private const ROUTE_OAUTH_START = 'shopify.oauth.start';

    private const QUERY_SHOP = 'shop';

    private const QUERY_SITE = 'site';

    // How far back the webhook-health counter looks.
    private const HEALTH_WINDOW_DAYS = 7;

    // i18n keys — never a literal in the page.
    private const TITLE = 'shopify.title';

    private const NAV_LABEL = 'shopify.nav';

    private const CONNECT_ACTION = 'shopify.connect.action';

    private const CONNECT_SHOP = 'shopify.connect.shop';

    private const CONNECT_SHOP_HELP = 'shopify.connect.shop_help';

    private const CONNECT_SHOP_PLACEHOLDER = 'shopify.connect.shop_placeholder';

    private const CONNECT_SUBMIT = 'shopify.connect.submit';

    private const CONNECT_INVALID_SHOP = 'shopify.connect.invalid_shop';

    private const DISCONNECT_ACTION = 'shopify.disconnect.action';

    private const DISCONNECT_HEADING = 'shopify.disconnect.confirm_heading';

    private const DISCONNECT_SUB = 'shopify.disconnect.confirm_sub';

    private const DISCONNECT_CTA = 'shopify.disconnect.confirm_cta';

    private const DISCONNECT_DONE = 'shopify.disconnect.done';

    // The form field the connect action collects.
    private const FIELD_SHOP = 'shop';

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

    /** The store's connection, or null when it was never connected. */
    public function connection(): ?ShopifyConnection
    {
        return $this->shopSite()->shopifyConnection;
    }

    /** False when the PLATFORM has no Shopify app credentials — connecting is impossible. */
    public function isPlatformConfigured(): bool
    {
        return app(ShopifyOAuth::class)->isConfigured();
    }

    /**
     * Webhook health for THIS shop: the topics Shopify confirmed, the last delivery, and how many
     * deliveries failed in the window.
     *
     * @return array{topics: array<int,string>, last_event_at: ?string, failed: int}
     */
    public function webhookHealth(): array
    {
        $connection = $this->connection();

        if ($connection === null) {
            return ['topics' => [], 'last_event_at' => null, 'failed' => 0];
        }

        $registration = $connection->webhook_registration ?? [];
        $since = now()->subDays(self::HEALTH_WINDOW_DAYS);

        $receipts = ShopifyWebhookReceipt::query()->where('shop_domain', $connection->shop_domain);

        return [
            'topics' => array_keys(is_array($registration) ? $registration : []),
            'last_event_at' => (clone $receipts)->max('created_at'),
            'failed' => (clone $receipts)
                ->where('status', ShopifyWebhookReceipt::STATUS_FAILED)
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }

    public function healthWindowDays(): int
    {
        return self::HEALTH_WINDOW_DAYS;
    }

    /**
     * Connect: collect the myshopify domain, then hand off to the OAuth start route. The domain is
     * normalized + validated by ShopifyOAuth (the same regex the callback enforces) so a typo never
     * becomes a redirect to an arbitrary host.
     */
    public function connectAction(): Action
    {
        return Action::make('connect')
            ->label(__(self::CONNECT_ACTION))
            ->icon('heroicon-o-link')
            ->visible(fn (): bool => $this->isPlatformConfigured())
            ->form([
                TextInput::make(self::FIELD_SHOP)
                    ->label(__(self::CONNECT_SHOP))
                    ->helperText(__(self::CONNECT_SHOP_HELP))
                    ->placeholder(__(self::CONNECT_SHOP_PLACEHOLDER))
                    ->required()
                    ->rule(fn (): callable => function (string $attribute, mixed $value, callable $fail): void {
                        if (ShopifyOAuth::normalizeShopDomain(is_string($value) ? $value : null) === null) {
                            $fail(__(self::CONNECT_INVALID_SHOP));
                        }
                    }),
            ])
            ->modalSubmitActionLabel(__(self::CONNECT_SUBMIT))
            ->action(function (array $data): void {
                $shop = ShopifyOAuth::normalizeShopDomain((string) ($data[self::FIELD_SHOP] ?? ''));

                if ($shop === null) {
                    Notification::make()->danger()->title(__(self::CONNECT_INVALID_SHOP))->send();

                    return;
                }

                $this->redirect(route(self::ROUTE_OAUTH_START, [
                    self::QUERY_SHOP => $shop,
                    self::QUERY_SITE => $this->shopSite()->getKey(),
                ]));
            });
    }

    /** Disconnect: the guarded uninstall transition (credentials wiped by the model). */
    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label(__(self::DISCONNECT_ACTION))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->connection()?->isInstalled() === true)
            ->requiresConfirmation()
            ->modalHeading(__(self::DISCONNECT_HEADING))
            ->modalDescription(__(self::DISCONNECT_SUB))
            ->modalSubmitActionLabel(__(self::DISCONNECT_CTA))
            ->action(function (): void {
                $connection = $this->connection();

                if ($connection === null) {
                    return;
                }

                app(ShopifyInstaller::class)->disconnect($connection);

                Notification::make()->success()->title(__(self::DISCONNECT_DONE))->send();
            });
    }

    /** @return array<int,Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->connectAction(),
            $this->disconnectAction(),
        ];
    }
}
