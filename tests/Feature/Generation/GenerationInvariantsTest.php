<?php

namespace Tests\Feature\Generation;

use App\Domain\Ai\ParsedCost;
use App\Domain\Generation\GenerateTryOnJob;
use Tests\TestCase;

/**
 * The non-negotiable Phase-6 invariants, asserted as code (the gatekeeper grep, made
 * executable): reservation TTL > job timeout, generations queue tries=1, the ONLY
 * charge path is CreditLedgerService, no model id / markup / prompt is a literal in a
 * generation service, and the ParsedCost null-cost-is-never-available coupling the
 * money path relies on to keep CreditMath::chargeMicroUsd(null) unreachable.
 */
class GenerationInvariantsTest extends TestCase
{
    public function test_parsed_cost_null_is_never_available_the_consumer_relies_on_this(): void
    {
        // The money-path null-cost guard is correct only because this coupling holds:
        // a null cost can NEVER be presented as available. ai-openrouter enforces it in
        // the ParsedCost constructor; we pin it here from the CONSUMER's perspective.
        $this->assertFalse(ParsedCost::unavailable()->available);
        $this->assertNull(ParsedCost::unavailable()->costUsd);

        // Even a directly-constructed "available but null" collapses to unavailable.
        $contradiction = new ParsedCost(null, true, ParsedCost::SOURCE_INLINE);
        $this->assertFalse($contradiction->available);
        $this->assertNull($contradiction->costUsd);
    }

    public function test_reservation_ttl_exceeds_the_generation_job_timeout(): void
    {
        $reservationTtl = (int) config('trayon.credits.reservation_ttl');
        $jobTimeout = (new GenerateTryOnJob(1, 1, 1))->timeout;

        // The in-flight reservation must outlive the worst-case generation so it never
        // expires mid-call and lets a second trigger bypass the reservation.
        $this->assertGreaterThan($jobTimeout, $reservationTtl, 'Reservation TTL must exceed the job timeout');
    }

    public function test_generations_queue_tries_is_one_no_retry_double_spend(): void
    {
        // A blind retry of the money path risks a double spend; the job declares tries=1
        // and the Horizon generations supervisor mirrors it (GEN_TRIES).
        $this->assertSame(1, (new GenerateTryOnJob(1, 1, 1))->tries);

        $horizon = config('horizon.defaults');
        $genSupervisor = collect($horizon)->first(fn ($s) => in_array('generations', (array) ($s['queue'] ?? []), true));
        $this->assertNotNull($genSupervisor, 'A Horizon supervisor must own the generations queue');
        $this->assertSame(1, (int) $genSupervisor['tries']);
    }

    public function test_the_job_is_dispatched_on_the_generations_queue(): void
    {
        $job = new GenerateTryOnJob(1, 1, 1);
        $this->assertSame(config('trayon.queues.generations'), $job->queue);
    }

    public function test_no_charge_path_exists_outside_the_credit_ledger_service(): void
    {
        // The ONLY writer of a `charge` ledger row is CreditLedgerService::charge.
        // No generation service inserts a charge row directly or writes the balance.
        $generationSources = $this->phpSourcesIn(app_path('Domain/Generation'));

        foreach ($generationSources as $file) {
            $code = file_get_contents($file);

            // No direct ledger insert / balance write in the pipeline.
            $this->assertStringNotContainsString('CreditLedger::create', $code, "Direct ledger write in $file");
            $this->assertStringNotContainsString('balance_micro_usd', $code, "Direct balance write in $file");
            $this->assertStringNotContainsString("DB::table('credit_ledger')", $code, "Raw ledger insert in $file");
        }
    }

    public function test_no_model_id_or_markup_literal_in_a_generation_service(): void
    {
        $generationSources = $this->phpSourcesIn(app_path('Domain/Generation'));

        foreach ($generationSources as $file) {
            $code = file_get_contents($file);

            // No hardcoded OpenRouter model id (would bypass the DB-managed resolver).
            $this->assertDoesNotMatchRegularExpression('#[\'"](google|openai|anthropic)/[a-z0-9.\-]+[\'"]#i', $code, "Hardcoded model id in $file");

            // No hardcoded markup multiplier literal (2.5 must come from the bag/config).
            $this->assertStringNotContainsString('* 2.5', $code, "Hardcoded markup in $file");
            $this->assertStringNotContainsString('× 2.5', $code, "Hardcoded markup in $file");
        }
    }

    /** @return list<string> */
    private function phpSourcesIn(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
