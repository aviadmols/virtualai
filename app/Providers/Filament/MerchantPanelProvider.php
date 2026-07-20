<?php

namespace App\Providers\Filament;

use App\Filament\Merchant\Pages\Dashboard;
use App\Filament\Merchant\Pages\Tenancy\EditSiteProfile;
use App\Http\Middleware\BindMerchantAccount;
use App\Http\Middleware\HtmlDirection;
use App\Http\Middleware\ShopifyFrameAncestors;
use App\Models\Site;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Merchant panel = the account owner's workspace (sites, scans, generations,
 * leads, credits view). Resources land here in Phase 8; this is the shell.
 * Marked default so the bare "/" of an authenticated merchant resolves here.
 * Brand: Amber. Theme + tokens are in resources/css/filament/merchant/theme.css.
 */
class MerchantPanelProvider extends PanelProvider
{
    // === CONSTANTS ===
    private const PANEL_ID = 'merchant';

    private const PANEL_PATH = 'merchant';

    private const THEME = 'resources/css/filament/merchant/theme.css';

    private const RESOURCE_NS = 'App\\Filament\\Merchant\\Resources';

    private const PAGE_NS = 'App\\Filament\\Merchant\\Pages';

    private const WIDGET_NS = 'App\\Filament\\Merchant\\Widgets';

    private const RESOURCE_DIR = 'Filament/Merchant/Resources';

    private const PAGE_DIR = 'Filament/Merchant/Pages';

    private const WIDGET_DIR = 'Filament/Merchant/Widgets';

    // OpenRouter look: Inter (Latin) is loaded via ->font(); Assistant covers
    // Hebrew (Inter has no Hebrew glyphs) via a HEAD render hook. Both come from
    // Bunny (a privacy-friendly Google Fonts mirror). The --to-font token stacks
    // them so HE never falls back. Weights 400–700 match the OpenRouter range.
    private const FONT_FAMILY = 'Inter';

    private const HEBREW_FONT_HEAD = '<link rel="preconnect" href="https://fonts.bunny.net">'
        .'<link href="https://fonts.bunny.net/css?family=assistant:400,500,600,700&display=swap" rel="stylesheet" />';

    /**
     * Nav group order for the merchant workspace (resources land in 8c–8g and
     * attach to these groups). Order is data, not scattered sorts. Labels
     * resolve via __() from the nav lang file.
     */
    private const NAV_GROUPS = [
        'nav.leads',
        'nav.marketing',
        'nav.credits',
        'nav.settings',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id(self::PANEL_ID)
            ->path(self::PANEL_PATH)
            ->login()
            // Direct sign-in for SSO-provisioned merchants. A Shopify install auto-creates the
            // owner with a RANDOM password (they only ever authenticate via the embedded session
            // token), so the account-menu profile page lets them set a password — and correct
            // their login email — to ALSO sign in at go.vsio.app/merchant/login directly.
            // NOTE: email-based ->passwordReset() is deferred until SMTP is configured — with the
            // `log` mailer it cannot deliver and would write reset tokens to the log. The in-panel
            // profile set-password is the recovery path until then.
            ->profile()
            // SHOP-centric + SINGLE-SHOP: the tenant is a Site (the "shop"), routed by its
            // unique slug, and per-shop URLs come from Filament tenancy. Account = one shop
            // (auto-provisioned at install / created by the super-admin), so the switcher
            // (tenantMenu) and self-service add-site (tenantRegistration) are intentionally
            // omitted; ownership is still enforced by User::getTenants / canAccessTenant.
            ->tenant(Site::class, slugAttribute: Site::TENANT_SLUG_FIELD)
            ->tenantProfile(EditSiteProfile::class)
            ->colors(self::colors())
            ->font(self::FONT_FAMILY)
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                static fn (): HtmlString => new HtmlString(self::HEBREW_FONT_HEAD),
            )
            ->viteTheme(self::THEME)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(self::navigationGroups())
            ->discoverResources(in: app_path(self::RESOURCE_DIR), for: self::RESOURCE_NS)
            ->discoverPages(in: app_path(self::PAGE_DIR), for: self::PAGE_NS)
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path(self::WIDGET_DIR), for: self::WIDGET_NS)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                HtmlDirection::class,
                // The panel renders inside the Shopify admin iframe (partitioned session
                // cookie). frame-ancestors names the exact shop when the embedded session
                // bridge stamped one, else the static Shopify pair — never an open frame.
                ShopifyFrameAncestors::class.':'.ShopifyFrameAncestors::MODE_PANEL,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // BindMerchantAccount is PERSISTENT tenant middleware: it runs AFTER Filament resolves
            // the shop tenant (so the super-admin drill-in can read it) yet still wraps the render,
            // binding the account for the whole request and clearing in finally (TS-TENANCY-001).
            ->tenantMiddleware([
                BindMerchantAccount::class,
            ], isPersistent: true);
    }

    /** Translated nav-group labels in the locked workspace order. */
    private static function navigationGroups(): array
    {
        return array_map(
            static fn (string $key): string => __($key),
            self::NAV_GROUPS,
        );
    }

    /**
     * The full semantic palette. Register EVERY color that a Filament action/badge uses
     * (primary + danger/success/warning/info), not just primary — an unregistered color
     * has no `--{color}-*` channel var, so its `bg-custom-600` / `bg-{color}-*` background
     * resolves to empty and the control renders transparent (the Activate/Delete buttons).
     * primary is remapped onto indigo in theme.css; the rest use Filament's palettes.
     */
    private static function colors(): array
    {
        return [
            'primary' => Color::Indigo,
            'danger' => self::spacedChannels(Color::Rose),
            'success' => self::spacedChannels(Color::Emerald),
            'warning' => self::spacedChannels(Color::Amber),
            'info' => self::spacedChannels(Color::Sky),
        ];
    }

    /**
     * Filament emits color channels comma-separated (`--danger-600: 225, 29, 72`), but this
     * panel's Tailwind color utilities are slash-form (`rgb(var(--danger-600) / <alpha>)`),
     * which is valid ONLY with SPACE-separated channels. Convert the palette to space form so
     * the emitted vars resolve. (See resources/css/filament/merchant/tailwind.config.js.)
     *
     * @param  array<int|string, string>  $palette
     * @return array<int|string, string>
     */
    private static function spacedChannels(array $palette): array
    {
        return array_map(
            static fn (string $channels): string => (string) preg_replace('/\s*,\s*/', ' ', $channels),
            $palette,
        );
    }
}
