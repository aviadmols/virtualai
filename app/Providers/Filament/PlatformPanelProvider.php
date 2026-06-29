<?php

namespace App\Providers\Filament;

use App\Filament\Platform\Pages\Dashboard;
use App\Http\Middleware\HtmlDirection;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Platform panel = the Super-Admin control plane (models, prompts, costs,
 * accounts, sites, credits). Resources land here in Phase 8; this is the shell.
 * Brand: Indigo. Theme + tokens are in resources/css/filament/platform/theme.css.
 */
class PlatformPanelProvider extends PanelProvider
{
    // === CONSTANTS ===
    private const PANEL_ID = 'platform';
    private const PANEL_PATH = 'platform';
    private const THEME = 'resources/css/filament/platform/theme.css';
    private const RESOURCE_NS = 'App\\Filament\\Platform\\Resources';
    private const PAGE_NS = 'App\\Filament\\Platform\\Pages';
    private const WIDGET_NS = 'App\\Filament\\Platform\\Widgets';
    private const RESOURCE_DIR = 'Filament/Platform/Resources';
    private const PAGE_DIR = 'Filament/Platform/Pages';
    private const WIDGET_DIR = 'Filament/Platform/Widgets';

    /**
     * Nav group order for the Super-Admin control plane (resources land in
     * 8c–8g and attach to these groups). Order is data, not scattered sorts.
     * Labels resolve via __() from the platform lang file.
     */
    private const NAV_GROUPS = [
        'platform.nav.overview',
        'platform.nav.accounts',
        'platform.nav.sites',
        'platform.nav.ai',
        'platform.nav.observability',
        'platform.nav.controls',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(self::PANEL_ID)
            ->path(self::PANEL_PATH)
            ->login()
            ->colors([
                'primary' => Color::Indigo,
            ])
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
            ]);
    }

    /** Translated nav-group labels in the locked control-plane order. */
    private static function navigationGroups(): array
    {
        return array_map(
            static fn (string $key): string => __($key),
            self::NAV_GROUPS,
        );
    }
}
