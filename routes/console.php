<?php

use App\Domain\Shopify\Webhooks\RecoverStuckShopifyWebhooksJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// === SCHEDULE (runs on the scheduler service: php artisan schedule:work) ===
// The scheduler is a single tick-emitter, NOT a worker — it enqueues, it does not
// run heavy work inline. Keep it to exactly one replica (no second schedule:work).

// Per-minute heartbeat. The health surface reads its age (green/yellow/red).
Schedule::command('trayon:heartbeat')->everyMinute()->withoutOverlapping();

// Shopify webhook recovery: re-dispatch receipts stuck in received/queued (a 200 was
// returned but the handler job was lost) + prune old receipt payloads. The receipt
// row is the durable source of truth, not the queue.
Schedule::job(new RecoverStuckShopifyWebhooksJob, config('trayon.queues.webhooks'))
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Media-retention purge (the command is owned by laravel-backend; the SCHEDULE
// host is owned here). Runs off-peak, chunked + idempotent, dispatching delete
// jobs onto the `media` queue. Enabled once the command lands in a later phase.
// Schedule::command('media:purge-expired')->dailyAt('03:00')->withoutOverlapping();
