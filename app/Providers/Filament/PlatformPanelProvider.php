<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
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
 */
class PlatformPanelProvider extends PanelProvider
{
    // === CONSTANTS ===
    private const PANEL_ID = 'platform';
    private const PANEL_PATH = 'platform';
    private const RESOURCE_NS = 'App\\Filament\\Platform\\Resources';
    private const PAGE_NS = 'App\\Filament\\Platform\\Pages';
    private const WIDGET_NS = 'App\\Filament\\Platform\\Widgets';
    private const RESOURCE_DIR = 'Filament/Platform/Resources';
    private const PAGE_DIR = 'Filament/Platform/Pages';
    private const WIDGET_DIR = 'Filament/Platform/Widgets';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(self::PANEL_ID)
            ->path(self::PANEL_PATH)
            ->login()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path(self::RESOURCE_DIR), for: self::RESOURCE_NS)
            ->discoverPages(in: app_path(self::PAGE_DIR), for: self::PAGE_NS)
            ->pages([
                Pages\Dashboard::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
