<?php

namespace App\Providers;

use App\Domain\Platform\QueueHealth;
use App\Models\Account;
use App\Models\AiModel;
use App\Observers\AccountObserver;
use App\Observers\AiModelObserver;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    // Livewire's temporary-upload ceiling (KB). The default (12MB) is too small for
    // the platform media-assets area (video/audio uploads); each FileUpload still
    // enforces its own tighter maxSize on top of this ceiling.
    private const UPLOAD_CEILING_KB = 51200;

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

        // The Models-page is_default/is_fallback toggle is the authoring surface for an
        // operation's model: this observer dedupes to one winner per operation and writes
        // it through to ai_operations.default_model/fallback_model (what the resolver reads).
        AiModel::observe(AiModelObserver::class);

        // Worker heartbeat: a running queue worker fires the Looping event on every poll
        // (even when idle), so stamp a short-lived cache key. The dashboard health widget
        // reads it to show "Worker: Active" for ANY worker (queue:work or Horizon).
        // Throttled so it isn't a Redis write on every loop.
        Queue::looping(static function (): void {
            $now = now()->timestamp;
            $last = (int) Cache::get(QueueHealth::HEARTBEAT_KEY, 0);

            if ($now - $last >= QueueHealth::HEARTBEAT_THROTTLE) {
                Cache::put(QueueHealth::HEARTBEAT_KEY, $now, QueueHealth::HEARTBEAT_TTL);
            }
        });

        // Raise Livewire's temporary-upload ceiling so media-asset video/audio
        // uploads fit; per-field maxSize rules stay the real limits.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.self::UPLOAD_CEILING_KB]]);

        // Behind Railway's TLS-terminating proxy the request can look like HTTP.
        // When the configured app URL is HTTPS, force HTTPS URL generation so
        // Filament assets and form actions are never blocked as mixed content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // EN/HE language switch shown in the topbar of BOTH Filament panels.
        // The chosen locale persists in the session; HtmlDirection reads it and
        // sets app locale + dir before render so every __() string and Filament's
        // own dir="rtl" resolve. A missing HE mirror is a release blocker — the
        // catalog (lang/en + lang/he) mirrors 1:1.
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch
                ->locales(['en', 'he'])
                ->labels([
                    'en' => 'English',
                    'he' => 'עברית',
                ]);
        });
    }
}
