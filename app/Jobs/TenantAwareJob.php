<?php

namespace App\Jobs;

use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for every tenant-touching queued job.
 *
 * Encodes the TS-TENANCY-001 fix so no subclass can re-earn the scar:
 *  - the account_id is carried EXPLICITLY in the constructor (never inferred
 *    from session / domain / config / the ambient Tenant left by a prior job);
 *  - handle() binds the tenant via Tenant::run(), which clears in `finally`,
 *    so a long-lived worker never leaks one job's tenant into the next.
 *
 * Subclasses implement process() — the business logic — and run with the
 * correct tenant already bound. They must NOT read Tenant::current() to decide
 * which account to act on; the account is $this->accountId.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $accountId,
    ) {}

    /**
     * Bind the explicit tenant for the duration of process(), always clearing
     * it in finally (via Tenant::run). This method is final so a subclass can
     * never bypass the self-clearing wrapper.
     */
    final public function handle(): void
    {
        Tenant::run($this->accountId, fn () => $this->process());
    }

    /** The tenant-scoped work. Runs with $this->accountId bound. */
    abstract protected function process(): void;
}
