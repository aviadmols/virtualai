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
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
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

    // OpenRouter look: Inter (Latin) is loaded via ->font(); Assistant covers
    // Hebrew (Inter has no Hebrew glyphs) via a HEAD render hook. Both come from
    // Bunny (a privacy-friendly Google Fonts mirror). The --to-font token stacks
    // them so HE never falls back. Weights 400–700 match the OpenRouter range.
    private const FONT_FAMILY = 'Inter';

    // The Vsio wordmark replaces the text brand name in the sidebar/header. A light +
    // dark SVG pair is swapped by theme (brand-logo.blade + brand-logo.css); height is
    // the --to-brand-logo-height token in this panel's theme.css.
    private const BRAND_LOGO_VIEW = 'filament.brand-logo';

    private const HEBREW_FONT_HEAD = '<link rel="preconnect" href="https://fonts.bunny.net">'
        .'<link href="https://fonts.bunny.net/css?family=assistant:400,500,600,700&display=swap" rel="stylesheet" />';

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
            ->colors(self::colors())
            ->font(self::FONT_FAMILY)
            ->brandLogo(fn () => view(self::BRAND_LOGO_VIEW))
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
     * the emitted vars resolve. (See resources/css/filament/platform/tailwind.config.js.)
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
