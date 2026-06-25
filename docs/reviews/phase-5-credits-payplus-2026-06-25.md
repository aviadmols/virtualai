## 2026-06-25T00:00:00Z — Phase 5 (Credit Ledger + Gates + PayPlus rail) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope: app/Domain/Credits/** (CreditLedgerService, CreditMath, CreditGate, CreditDenied, Reservation,
  ReservationManager, IdempotencyKey, UsageGate, GateDenied), app/Domain/Credits/Payments/** (PayPlusProvider,
  CreditPaymentProvider, PurchaseIntent, PurchaseResult, PurchaseInitiator, PurchaseReconciler, PurchaseRouter,
  CreditProviderResolver), app/Domain/Leads/** (LeadGate, LeadDecision, LeadCapture), app/Domain/Activity/ActivityRecorder,
  Models (CreditLedger, CreditPurchase, EndUser, Account), AccountObserver, CreditsServiceProvider,
  PurchaseWebhookController, routes/webhooks.php, bootstrap/app.php, config/trayon.php,
  migrations 2026_06_25_110000..110003 + 120001..120002.

Sweeps run:
  withoutGlobalScope (clean — only comments + the documented PurchaseRouter routing note)
  raw DB:: on tenant table (clean — PurchaseRouter::accountIdForRef is integer-id routing only, returns account_id; HealthController select 1 is non-tenant)
  hardcoded markup / float money (clean — multiplier from operation.credit_multiplier ?? config('trayon.pricing.markup_default'); all money integer micro-USD)
  non-deterministic idempotency key (clean — all keys deterministic; provider_ref minted via ULID is a per-attempt page id, not a ledger key)
  Blade::render on templated text (clean — none; PayPlus description is internal sprintf, not merchant-edited)
  secrets logged (clean — PayPlus logs only safe shape: path/status/exception-class/booleans; never key/payload)
  marketing consent default (clean — column default false + model attribute false + LeadCapture opt-in only)
  balance/reserved writers (clean — CreditLedgerService + ReservationManager are the only mutators; no mass-assign of balance exists)
  GlobalModels allow-list (clean — CreditLedger/EndUser/CreditPurchase NOT on it; all carry BelongsToAccount)
  unique indexes (confirmed — credit_ledger.idempotency_key UNIQUE; credit_purchases.idempotency_key UNIQUE; end_users (site_id,anon_token) UNIQUE)

Tests run: php artisan test → 229 passed / 628 assertions (full suite green).
  Filtered Credits|Leads|Tenancy → 134 passed / 359 assertions.
  Safety tests confirmed MEANINGFUL (not theatre), read assertion-by-assertion:
   - CreditLedgerServiceTest: release_on_failure asserts 0 charge rows + hasCharge()===false; double_charge asserts 1 row + same id;
     double_charge_blocked_at_db asserts QueryException on duplicate raw insert (LAYER 1 proven).
   - PurchaseWebhookSignatureTest: forged/unsigned → 0 ledger rows + unchanged balance + still-pending; replay → exactly 1.
   - PurchaseIsolationTest: unbound query fails closed (0 rows); webhook for A never credits B (resolves account from OUR row).
   - AppendOnlyLedgerTest: update/delete throw; row unchanged after blocked update; no updated_at column.
   - MarketingConsentDefaultTest: default off; signup doesn't imply; photo consent independent.

MONEY-SAFETY VERDICT: PASS. No charge without a credit_ledger row; CreditLedgerService is the sole writer of
  balance_micro_usd + ledger rows (forceFill, row-locked, balance_after in the same txn). Reserve-before / charge-on-success /
  release-on-failure law upheld. Four-layer double-charge wall present and DB-backed (unique key + account row lock + ledger
  pre-check + client_request_id key segment). Markup never literal. Append-only enforced at the model. Webhook idempotent +
  signature-verified (fail-closed) + account resolved from our row, never the body. Typed denials throughout (no 500s).

TENANT-SAFETY VERDICT: PASS (independent of saas AUDIT-PASS, re-confirmed). credit_ledger / end_users / credit_purchases all
  carry account_id NOT NULL + BelongsToAccount and are absent from the allow-list. Every idempotency key embeds account_id as
  its first post-prefix segment. The webhook binds the tenant via Tenant::run(resolved account); PurchaseRouter is the single,
  documented integer-only routing seam (returns account_id, never row data) — not a withoutGlobalScopes path, not a data read hole.

Blocking: NONE.

Suggestions:
  #S1 ReservationManager.php:69-73 — release() guards with a non-atomic has()+forget() pair; a concurrent double-release
      (failure path racing finalize, two workers) can pass has() twice and decrement reserved_micro_usd twice. Impact is
      bounded: adjustReserved clamps at max(0,…) so it can only UNDER-count reserved (spendable transiently higher), never
      double-charge or corrupt the ledger/balance. Use an atomic pull() (get-and-forget) or use forget()'s boolean return as
      the guard. (Owner: laravel-backend.) SUGGESTION — reservation-accounting hardening, not money-safety.
  #S2 PurchaseReconciler.php:76-91 — the comment "the provider-confirmed amount is asserted to match it below" overstates the
      code: the credited figure is the persisted face value (locked->credits_micro_usd) and the provider-confirmed amount is
      only recorded in meta, never compared. Behaviour is SAFE (the credited amount is never client-trusted), but add the
      defense-in-depth assertion (confirmed >= expected, else flag/quarantine) or fix the comment to match. (Owner: saas-credits-billing.)
  #S3 PurchaseRailTest.php:144-160 (documented behaviour, scope deferral) — a provider REFUNDED webhook arriving after a paid
      purchase hits the ledger_id wall and is a TOTAL no-op: status stays 'paid', no refund/clawback recorded. A genuine PayPlus
      refund/chargeback would leave merchant credits intact and the row showing paid. Track a follow-up (mark refunded + decide
      clawback policy). (Owner: saas-credits-billing / roadmap.) SUGGESTION — deferred money-correctness gap, not Phase-5 regression.
  #S4 Account.php:38-39 — balance_micro_usd / reserved_micro_usd are in $fillable. No code mass-assigns them today (the ledger
      writer uses forceFill), so this is latent, but it leaves a mass-assignment surface on the money columns. Remove them from
      $fillable (the factory can set them via forceFill/explicit state). (Owner: laravel-backend.) SUGGESTION — money-column hardening.

Nits:
  #N1 credit_ledger.idempotency_key is a GLOBAL unique (not unique(account_id,key)). Safe ONLY because every key embeds
      account_id (IdempotencyKey) — the safety rests on key-construction discipline, not the schema. Worth a one-line comment
      tying the two together. (Owner: laravel-backend.)

Re-review: NOT required to advance. Suggestions are recorded for owner discretion; none gates the phase.
Recurring → archivist: none new. Note for registry: "reservation release atomicity (has+forget non-atomic)" and "post-paid
  refund webhook is a no-op" as classes to watch in the Phase 6 generation pipeline and the future refund flow.
