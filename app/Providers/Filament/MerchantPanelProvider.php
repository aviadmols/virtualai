<?php

namespace App\Providers\Filament;

use App\Filament\Merchant\Pages\Dashboard;
use App\Filament\Merchant\Pages\Tenancy\EditSiteProfile;
use App\Filament\Merchant\Pages\Tenancy\RegisterSite;
use App\Http\Middleware\BindMerchantAccount;
use App\Http\Middleware\HtmlDirection;
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
        'nav.sites',
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
            // SHOP-centric: the tenant is a Site (the "shop"), routed by its unique slug. The
            // switcher + per-shop URLs come from Filament tenancy; ownership is enforced by
            // User::getTenants / canAccessTenant (the ACCOUNT stays the security boundary).
            ->tenant(Site::class, slugAttribute: Site::TENANT_SLUG_FIELD)
            ->tenantMenu()
            ->tenantRegistration(RegisterSite::class)
            ->tenantProfile(EditSiteProfile::class)
            ->colors([
                'primary' => Color::Indigo,
            ])
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
}
