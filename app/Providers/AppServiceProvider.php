<?php

namespace App\Providers;

use App\Models\Account;
use App\Observers\AccountObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every new account gets its single opening $5 grant via the ledger writer.
        Account::observe(AccountObserver::class);

        // Behind Railway's TLS-terminating proxy the request can look like HTTP.
        // When the configured app URL is HTTPS, force HTTPS URL generation so
        // Filament assets and form actions are never blocked as mixed content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
