## 2026-07-05T00:00:00Z — Two release-blocker-sensitive commits (fa68529 tenant-bind lifetime · 836e44b flat-rate charge) — VERDICT: GREEN
Reviewer: code-review-gatekeeper
Scope:
  fa68529 — app/Http/Middleware/BindMerchantAccount.php, app/Support/Tenant.php (new bindForRequest),
            app/Models/User.php (getTenants), tests/Feature/Tenancy/MerchantPanelBindingTest.php
  836e44b — app/Domain/Ai/AiOperationResolver.php, app/Domain/Ai/OperationConfig.php,
            app/Domain/Ai/TryOnGenerationCaller.php, app/Domain/Generation/GenerateTryOnJob.php,
            app/Domain/Generation/GenerationFailureCode.php,
            app/Filament/Platform/Resources/AiModelResource.php, lang/en+he/platform.php,
            tests/Feature/Filament/Platform/AiModelCostHintTest.php,
            tests/Feature/Generation/GenerateTryOnJobTest.php

Sweeps run (both diffs):
  withoutGlobalScopes  — clean (only doc-comments "NEVER/No withoutGlobalScopes"; the sole real call is the
                         pre-existing audited MerchantSiteTenancy::resolveBySlug, gated by canAccessTenant — not touched here)
  Blade::render        — clean (only "NEVER Blade::render" comments; all templating via strtr)
  raw DB::table/select — clean (none in changed files)
  hardcoded model-id   — clean (no model literal in any service; seedream ids appear only in tests)
  hardcoded markup 2.5 — clean (charge multiplier = $config->creditMultiplier ?? CreditMath::multiplierFor();
                         multiplierFor reads operation.credit_multiplier ?? config('trayon.pricing.markup_default'))
  float money          — clean (?float is only the markup RATIO; money stays integer micro-USD end to end)
  inline CSS / style=  — clean
  i18n en/he 1:1       — clean (platform.php 371 == 371; all 3 new keys mirrored:
                         models.field.cost_hint_required_byteplus, generation.cost_not_configured, generation.cost_unavailable)

Tests run (by hand, this review):
  php artisan test  MerchantPanelBindingTest + GenerateTryOnJobTest + AiModelCostHintTest
  -> 20 passed (105 assertions). Includes the back-to-back / fail-closed / double-charge / refund-on-failure /
     flat-rate-no-price-no-charge guards. Each is meaningful (would go red if its guard were removed).

=== fa68529 TENANT-SAFETY (release blocker) — PASS ===
Deployment: web tier runs `frankenphp run --config Caddyfile` in CLASSIC mode (Laravel Octane is NOT installed;
  no octane:frankenphp worker command). Static Tenant::$accountId is therefore re-initialised to null per request
  at the language level, like PHP-FPM. The code is nonetheless written leak-safe even under a persistent-worker
  model (correct defensive posture should Octane be enabled later). Three leak-safety properties confirmed:
   1. bindForRequest clears-first then sets (Tenant.php:92-96) — a stale prior binding is REPLACED, never merged.
      Proven by test_a_stale_binding_is_replaced_not_merged.
   2. A no-account request CLEARS (BindMerchantAccount.php:51-55: Tenant::clear() before $next) — it never inherits
      the previous request's tenant. This is the fix vs. the old null-branch no-op.
      Proven by "authenticated user without an account fails closed" + "no authenticated user fails closed".
   3. app->terminating(clear) at request end (BindMerchantAccount.php:64) — belt-and-suspenders.
  Residual: app->terminating may not fire if an exception unwinds past the middleware, but properties 1+2 make the
  NEXT request self-heal regardless, so no leak survives into a differently-authed next request.
  getTenants (User.php:130-137): fail-closes for account_id === null (empty collection); otherwise
  MerchantSiteTenancy::sitesForAccount binds the caller's OWN account_id via Tenant::run and reads through the
  normal fail-closed AccountScope — can only ever return that account's sites. The guard change
  (isSuperAdmin() || null  ->  null only) only broadens the switcher to a super-admin who ALSO owns an account,
  still scoped to THEIR OWN account. No cross-account switcher leak. canAccessTenant still requires
  tenant.account_id === user.account_id for a non-super-admin (User.php:161). The change only NARROWS; no new
  withoutGlobalScopes.
  Back-to-back different-account coverage present: "two merchants back to back never cross contaminate" passes.

=== 836e44b MONEY-SAFETY (release blocker) — PASS ===
  Order preserved (GenerateTryOnJob::process): early-fail (flatRatePriceMissing, line 143) BEFORE CreditGate (151),
  BEFORE reserve (156), BEFORE any provider call. failOnMisconfiguration takes NO reservation, writes NO charge,
  does NOT consume a free try (pending -> cancelled, like a gate denial). Proven by
  test_byteplus_model_without_a_price_fails_before_any_provider_call (Http::assertNothingSent, balance intact,
  reserved==0, 0 charges, generations_used==0).
  flatRatePriceMissing (OperationConfig:84-105) fires ONLY when EVERY usable attempt is flat-rate-with-no-price:
  returns false the moment the primary is OpenRouter, or a fallback is OpenRouter, or any flat-rate attempt has a
  positive price — so an OpenRouter attempt is never blocked early (its real inline cost is knowable only after
  the call).
  Flat-rate charge = resolved model cost_hint_micro_usd (positive-only) ?? operation estimate (positive-only),
  single-sourced in AiOperationResolver::costHintForModel (reads the AiModel catalog — a global allow-list model,
  correctly unscoped). BytePlusImageClient::parseCost uses that positive price as the authoritative cost, else
  ParsedCost::unavailable (fail-closed -> COST_UNAVAILABLE -> release, no charge). OpenRouter::parseCost UNCHANGED
  (still inline/endpoint cost; the passed value is only the unavailable-estimate carrier). Charge math unchanged:
  round(cost x multiplier) with the multiplier from config/DB. Proven by
  test_byteplus_model_with_a_price_generates_and_charges_price_times_markup (charge == price x 2.5, exactly one
  ledger row, balance debited).
  AiModelResource requires a strictly-positive per-image price for provider=byteplus (form guard), preventing the
  price-less save that caused the silent cost_unavailable. Proven by
  test_a_byteplus_model_cannot_be_saved_without_a_per_image_price; OpenRouter models may still save price-less.

Blocking: (none)
Suggestions: (none)
Nits: (none)
Re-review: not required.
Recurring -> archivist: none new. The BytePlus flat-rate cost_unavailable class of bug is now closed by a
  form-level required-positive-price guard + an early pre-reserve fail; worth a TROUBLESHOOTING.md note that a
  flat-rate provider (no inline USD cost) MUST carry a per-model price hint or the money path fails closed.
