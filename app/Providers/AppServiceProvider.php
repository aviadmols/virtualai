<?php

namespace App\Providers;

use App\Models\Account;
use App\Observers\AccountObserver;
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
    }
}
