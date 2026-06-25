<?php

namespace App\Providers;

use App\Domain\Generation\CreditEstimator;
use App\Domain\Media\MediaStorage;
use Illuminate\Support\ServiceProvider;

/**
 * GenerationServiceProvider — wires the generation pipeline's singletons.
 *
 * MediaStorage (the single media gateway) and CreditEstimator (the reserve-amount
 * helper) are stateless and shared. The AI caller, the resolver, the ledger writer
 * and the reservation manager are already bound (Ai/Credits providers); the job
 * resolves them at run time via the container. There is no interface to bind here —
 * every dependency is a concrete, auto-resolvable class.
 */
class GenerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaStorage::class);
        $this->app->singleton(CreditEstimator::class);
    }
}
