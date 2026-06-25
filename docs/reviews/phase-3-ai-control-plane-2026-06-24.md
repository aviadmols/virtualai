## 2026-06-24T00:00Z — Phase 3 — AI Control Plane — VERDICT: GREEN (PASS-WITH-SUGGESTIONS)
Reviewer: code-review-gatekeeper
Lead: ai-openrouter

Scope (reviewed):
- Migrations: ai_operations, ai_models, prompts (2026_06_24_110000/110001/110002).
- Models: AiOperation, AiModel, Prompt.
- Domain/Ai: OperationConfig, AiOperationResolver, OpenRouterClient, OpenRouterException,
  ParsedCost, ImagePayload, ProductScanCaller, TryOnGenerationCaller, ScanResult, TryOnResult.
- AiControlPlaneSeeder + AiModelFactory/AiOperationFactory/PromptFactory.
- Tests: tests/Unit/Ai/PromptSubstitutionTest; tests/Feature/Ai/{AiOperationResolver,
  OpenRouterClient,ProductScanCaller,TryOnGenerationCaller}Test.

Sweeps run:
- hardcoded model/quality/aspect in services -> CLEAN (all hits are comments or bag-reads;
  the only model-id literals live in seeder/factories/migration-comments, never a service).
- Blade::render / eval / render-on-prompt -> CLEAN (substitution is strtr only; 3 hits are
  "NEVER Blade::render" comments).
- withoutGlobalScope -> CLEAN (only a comment in BelongsToAccount.php).
- raw DB::table/statement/select on tenant tables -> CLEAN (one DB::select('select 1') in
  HealthController, not a tenant table; not Phase 3).
- secrets in logs/dumps -> CLEAN (single Log::info openrouter.call logs a masked key hint only).
- OPENROUTER key on browser/widget surface -> CLEAN (no widget code in Phase 3; key read only
  from services.openrouter.key via withToken).
- ledger/markup/charge writes in app/Domain/Ai -> CLEAN (no credit_ledger row, no markup applied;
  cost surfaced as USD + creditMultiplier passthrough only).

Tests run:
- php artisan test tests/Unit/Ai tests/Feature/Ai -> 35 passed (79 assertions).
- php artisan test (full) -> 65 passed (157 assertions); no regression.

Contract checks (all PASS):
- AI configurable, not hardcoded: AiOperationResolver::for() is the single source of the bag;
  model/prompt/quality/aspect/seed all read from DB. No service literal. PASS.
- Templating strtr, never Blade: OperationConfig::substitute() is a literal strtr swap; the
  RCE test proves hostile Blade in BOTH template and value renders literally. PASS.
- Resolution order site -> account -> product_type -> global, first match wins, global always
  exists (RuntimeException if missing). Proven by AiOperationResolverTest. PASS.
- Secrets: key read only from config, masked in logs (test asserts the bearer never hits a log
  line), never on a browser surface. PASS.
- Money discipline: layer returns actual_cost_usd + model_used + creditMultiplier passthrough;
  writes no ledger row, applies no markup; cost never guessed (ParsedCost::unavailable). PASS.
- Tenancy nuance: Prompt is NOT BelongsToAccount and IS on GlobalModels::ALLOW_LIST. Account/site
  resolver legs ALWAYS constrain account_id; product_type/global legs whereNull('account_id').
  Cross-account non-leak proven by two dedicated tests (site + account scope). No
  withoutGlobalScopes anywhere. PASS.
- CONST-at-top: present where constants exist; pure DTOs (OperationConfig/ScanResult/TryOnResult)
  have no literals to hoist. English-only comments, small SRP classes. PASS.

Blocking: NONE.

Suggestions (recorded, do not gate):
- S1 [data-integrity] database/migrations/...110002_create_prompts_table.php:30-69 — prompts has
  no unique constraint. Two active rows at the same (scope, account_id, site_id, operation_key,
  product_type) make resolver ->first() pick one non-deterministically. Add a partial/unique index
  per resolution leg (e.g. unique(account_id, site_id, operation_key, is_active) for site scope) so
  a duplicate active prompt is impossible rather than silently order-dependent. Not an isolation
  hole (every tenant leg is account_id-constrained), hence SUGGESTION.
- S2 [robustness] app/Domain/Ai/AiOperationResolver.php:91 — a per-site override that equals the
  operation default but is NOT in the (sparse) ai_models catalog silently falls through to the
  operation default. Correct outcome, but consider logging when a configured site override is
  dropped so an admin learns their override is being ignored.
- S3 [convention nit] app/Domain/Ai/ParsedCost.php:23-25 — the SOURCE_* consts sit after the
  constructor rather than in a CONSTANTS-at-top block. Cosmetic ordering only.

Nits:
- N1 app/Domain/Ai/OpenRouterClient.php:308 — extractEndpointCost falls back to data['usage'] as a
  cost candidate; usage is usually an object, is_numeric guards it, so harmless. Consider narrowing
  to a known cost field for clarity.

Re-review: NOT required. Suggestions routed to ai-openrouter / laravel-backend (S1 migration owner)
for discretionary follow-up; none gate Phase 3.

Recurring -> archivist: none new. Positive pattern worth recording: the dual-scope global model
(Prompt on ALLOW_LIST with explicit account_id-constrained resolver scopes) is the sanctioned way
to mix platform-global + tenant rows in one table without a fail-closed global scope.

GATE: GREEN — Phase 3 (AI Control Plane) may advance. AI-PLANE-GREEN.
