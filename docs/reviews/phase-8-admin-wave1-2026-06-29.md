## 2026-06-29T00:00:00+03:00 — Phase 8 Wave 1 (8a backend read-contracts + resolver-preview; 8b design-token/theme/i18n foundation) — VERDICT: GREEN
Reviewer: code-review-gatekeeper
Commit: 44076a9 (diff vs 44076a9~1) — 71 files, +7315/-25
Scope:
  - 8a backend: app/Domain/Reporting/{DashboardMetrics,DashboardMetricsBuilder,CostsMetrics,CostsMetricsBuilder,MetricWindow}.php;
    app/Domain/Leads/{LeadAttempt,LeadAttemptHistory,LeadsExporter}.php
  - 8a ai: app/Domain/Ai/AiOperationResolver.php (preview()+resolveInternal split);
    app/Domain/Ai/Preview/{OperationPreview,ResolutionStep,ResolutionTrace,ResolvedOperation}.php
  - 8b design: resources/css/to/tokens.css; resources/css/filament/** (themes+shared components);
    resources/views/components/to/{kpi,cta,badge,empty-state}.blade.php; app/Support/Ui/StatusBadge.php;
    app/Http/Middleware/HtmlDirection.php; app/Providers/{AppServiceProvider, Filament/PlatformPanelProvider, Filament/MerchantPanelProvider}.php
  - i18n: lang/en/* (16 files) + lang/he/* (16 files); tests/Feature/{Reporting,Leads,Ai,Tenancy}/*

Sweeps run (all on the changed surface):
  - withoutGlobalScopes → CLEAN (every hit is a comment NEGATING it; none in code)
  - DB::table on tenant table → 1 hit (CostsMetricsBuilder:40) — CONFIRMED SAFE: platform-wide aggregate
    returning only COUNT/SUM(ABS(amount))/SUM(actual_cost) — no hydrated row, no PII, no per-account leak
  - Blade::render / ->render / eval → CLEAN (all hits are comments warning against it; substitution is strtr)
  - hardcoded model id / quality / aspect_ratio in a service → CLEAN (all model/quality/ratio values flow from
    AiOperationResolver/OperationConfig; the only literal model ids are inside the resolver-preview TEST asserting seeded values)
  - markup literal (2.5 / *2.5) → CLEAN (only in comments + config keys trayon.pricing.markup_default; read via config())
  - float money → CLEAN (money is integer micro-USD everywhere; float only in display-only *Usd() helpers and ratios)
  - inline style=" in admin Blade → CLEAN
  - Tailwind arbitrary values [..] in to/* components → CLEAN
  - physical-direction CSS (margin-left/right, left:, text-align:left/right) in resources/css → CLEAN (logical props only; RTL via text-align:start)
  - secrets logged/rendered → CLEAN (only log is AiOperationResolver:281 override-dropped: operation_key+site_id+model_id+reason — non-sensitive; no OpenRouter key, no widget_secret)
  - User::query() bare read in reporting/leads → NONE (the new reads are on EndUser/Site/Product/Generation, all BelongsToAccount, inside Tenant::run; no User model read at all)
  - i18n key-diff (flattened, both directions, all 16 file pairs) → 0 missing keys EN↔HE, 0 untranslated English-fallback strings left in any lang/he file (verified by PHP flatten + array_diff_key + latin/hebrew heuristic)

Tests run:
  - php artisan test --filter "Reporting|Leads|AiOperationResolverPreview|Tenancy" → 111 passed (377 assertions)
  - php artisan test (full) → 342 passed (1107 assertions), 26s. Matches commit-message claim.

Meaningfulness (would-go-red checks):
  - ReportingIsolationTest: asserts Tenant::check()===false after EVERY builder call (no leaked bind);
    cross-checks A/B distinct lead emails never cross exports; test_unbound_metrics_query_fails_closed clears the
    tenant and asserts EndUser read returns 0 → goes RED if BelongsToAccount removed or withoutGlobalScopes used.
  - AiOperationResolverPreviewTest: Http::preventStrayRequests()+assertNothingSent() (no HTTP → RED if preview called OpenRouter);
    DB::listen for INSERT/UPDATE/DELETE asserts [] (no writes → RED if preview wrote); injects "{{ 7*7 }} @php" and asserts
    verbatim output with no "49"/"x" (RCE-safe → RED if Blade::render used); winner-parity with for() at every precedence level.
  - CostsMetricsTest / DashboardMetricsTest: assert exact integer micro-USD sums + realized markup; not theater.

Tenant-safety (release-blocker category): PASS.
  - DashboardMetricsBuilder + LeadsExporter + LeadAttemptHistory all run reads inside Tenant::run($account) on BelongsToAccount
    models; account passed EXPLICITLY (never ambient). LeadAttemptHistory binds Tenant::run((int)$endUser->account_id).
  - CostsMetricsBuilder is the ONE platform-wide aggregate; returns sums/counts only (the documented exception), never PII.
  - AiOperationResolver prompt legs: site/account constrain account_id explicitly; product_type/global constrain whereNull(account_id).
    Prompt is on the documented global allow-list; resolver applies explicit account_id filters — no cross-account read.
  - Cross-references saas-credits-billing's isolation audit (TS-TENANCY-003 path) — independently re-confirmed via the fail-closed test.

Money-safety: PASS. Markup target read from config('trayon.pricing.markup_default'); all money integer micro-USD; markupRealized/marginRatio are ratios computed from integer sums (display only).
AI-configurability: PASS. preview() reuses for()'s shared resolveInternal() core (no drift); no HTTP, no writes; strtr-only substitution.
Template safety: PASS. strtr literal swap; no Blade::render on any prompt/template/email path in the diff.
CONST-at-top: PASS. Every new PHP/Blade/CSS file opens with its constants/token-reference block.
Inline CSS / Tailwind arbitrary: PASS (zero in admin UI). i18n: PASS (1:1 mirror, 0 drift). Secrets: PASS.

Blocking: NONE.
Suggestions:
  - [SUGGEST] app/Http/Middleware/HtmlDirection.php:47 — the ?locale= query override is unauthenticated and writes the
    chosen locale to the session. It is bounded to the SUPPORTED allow-list (en|he) and only affects UI locale/dir (no data,
    no privilege), and is documented for the Playwright RTL gate. Low risk; consider gating the query override to non-production
    or to the RTL test path so a shared session's locale can't be flipped by a crafted link. Owner: admin-design-system.
Nits: none.

Re-review: not required. Gate may flip GREEN; Wave 2 may start.
Recurring → archivist: none new. Positive note for the registry: the resolveInternal() single-core pattern (for()/preview()
sharing one decision path) is the proven shape that prevents preview/real drift — worth recording as a "good pattern".
