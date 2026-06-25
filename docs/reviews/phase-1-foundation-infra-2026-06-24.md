## 2026-06-24T00:00:00Z — Phase 1 (Foundation + Infra, railway-infra lead) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope (22 authored files): Dockerfile · Caddyfile · Procfile · railway.toml · scripts/docker-web.sh · scripts/predeploy.sh ·
  app/Console/Commands/PredeployCheck.php · app/Console/Commands/SchedulerHeartbeat.php · app/Support/Health/Heartbeat.php ·
  app/Http/Controllers/HealthController.php · app/Providers/Filament/{Platform,Merchant}PanelProvider.php ·
  app/Providers/HorizonServiceProvider.php · config/{horizon,queue,trayon,services,filesystems,database}.php ·
  routes/{web,console}.php · .env.example · composer.json · bootstrap/app.php

Sweeps run:
  - STRIPE in product source (app/ config/ routes/ .env.example) — CLEAN (only agent docs + payplus|stripe enum owned by saas-credits-billing)
  - leaked secrets (sk-or-, sk_live/test, committed key values) — CLEAN (all via env(), .env.example placeholders empty)
  - hardcoded model id / aspect ratio in app/ — CLEAN (no AI code yet; correctly deferred)
  - inline style=" in product views/widget — CLEAN (no UI yet; hits are agent docs/DoD only)
  - withoutGlobalScope / DB::table / Blade::render / account_id in app/ — NONE (no tenant/credit/AI feature code; deferred to Phase 2/5/6)
  - bare ^const in config/ and routes/ — NONE (TS-INFRA-003 guarded defined()||define() applied everywhere)
  - real const in class files — PRESENT in all 5 const-bearing classes; 0 define() calls in classes
  - non-English comments in app/config/routes/scripts — NONE

Commands run (evidence, not assertion):
  - php artisan --version -> Laravel 11.54.0 (matches locked contract; TS-BUILD-001 honoured)
  - php artisan config:cache && route:cache && view:cache -> all SUCCEED, no "Constant already defined" (TS-INFRA-003 verified live)
  - predeploy guard, production + missing APP_KEY/TENANT_CREDENTIALS_KEY/OPENROUTER_API_KEY -> exit code 1 (FAIL-CLOSED verified)
  - predeploy guard inside `set -eu` shell -> aborts before migrate step (migrate never reached; deploy refused)
  - predeploy guard refuses DB_CONNECTION=sqlite in production; requires queue.default=redis + cache.default=redis; requires media bucket
  - predeploy guard, valid local env -> exit 0 (passes)
  - php artisan test -> 2 passed (default example tests; no Phase-1 safety guards exist yet, money/tenant tests deferred)
  - caches cleared after run; repo left clean

Verified-good (the Phase-1 load-bearing guarantees):
  - Fail-closed predeploy guard (PredeployCheck.php) — missing critical env / sqlite-in-prod / non-redis drivers all refuse the deploy with exit 1.
  - Horizon queue isolation (config/horizon.php) — 5 separate supervisors, one queue each; `generations` capped at GEN_MAX_PROCS=8 in its own
    supervisor and CANNOT starve `webhooks`/`scans` (each has its own process pool). GEN_TRIES=1 prevents double OpenRouter spend on retry.
  - retry_after invariant (config/queue.php) — redis retry_after default 120 > GEN_TIMEOUT 70, so a still-running generation is not re-reserved.
  - Build-time config cache removed (Dockerfile:36, docker-web.sh, predeploy.sh) — no keyless cache baked without OPENROUTER/APP_KEY/TENANT_CREDENTIALS_KEY.
  - exec PID 1 in Procfile (web/worker/scheduler) — Railway SIGTERM drains in-flight generations gracefully.
  - No global healthcheckPath in railway.toml (TS-INFRA-002 honoured) — worker/scheduler deploys won't restart-loop.
  - TENANT_CREDENTIALS_KEY env-separated from APP_KEY (.env.example, config/trayon.php) — independent rotation per ARCHITECTURE.md.
  - Two logical Redis DBs (config/database.php) — queue/Horizon/locks/heartbeat on DB 0, cache on DB 1; a cache:clear can't wipe queued jobs.
  - PayPlus-only rail; no Stripe; OpenRouter key server-side only; both Filament panels are clean auth-gated shells with CONST-at-top.
  - HorizonServiceProvider viewHorizon gate is fail-closed (empty allow-list => no one in prod until an email is added).

Blocking: NONE.

Suggestions (recorded, do NOT gate):
  #1 SUGGESTION  app/Http/Controllers/HealthController.php:62,79 — /health (public, unauthenticated, no middleware) returns raw
     $e->getMessage() for DB/Redis failures, which can disclose host/port/DSN connection metadata to anyone. Gate the verbose body
     behind APP_DEBUG (or an internal token); return only the status color publicly. Rule: secrets/infra-detail minimization. Low impact
     (only on failure; no credentials or tenant data exposed) — hardening, not a money/tenant issue.
  #2 SUGGESTION  routes/web.php:17 — /health hits DB + Redis on every call with no throttle middleware; an unauthenticated un-throttled
     DB-touching endpoint is a minor DoS-amplification surface. Add a `throttle` middleware (rate-limit numbers owned by railway-infra).
  #3 SUGGESTION  app/Console/Commands/PredeployCheck.php — guard does not assert SESSION_DRIVER=database (env contract) nor APP_DEBUG=false
     in production; a prod boot with APP_DEBUG=true leaks stack traces. Consider adding both to the production checks. Env management is
     railway-infra's call; advisory only.
  #4 SUGGESTION  config/queue.php:79 — redis retry_after reads env('REDIS_QUEUE_RETRY_AFTER') with no floor; an operator setting it below
     GEN_TIMEOUT (70) would re-reserve a still-running generation (double spend). Consider clamping to max(env, 120) or asserting it in
     the predeploy guard. The default (120) is correct; this hardens against operator misconfig.

Nits: NONE.

Re-review: NOT required. Suggestions are at railway-infra's discretion and do not gate the Phase-1 gate.

Universal gate (Phase 1 subset): PASS — no tenant/credit/AI feature code present (correctly deferred); no hardcoded model id; OpenRouter key
  absent from any browser surface (no widget yet); CONST-at-top respected (classes use const, config/routes use guarded define per TS-INFRA-003);
  no inline CSS; no Blade::render. §7.1 phase gate: PASS — predeploy fails closed; CACHE_STORE/QUEUE_CONNECTION=redis, SESSION_DRIVER=database
  in env contract; no secret baked into cached config; locked 5-queue set present; TENANT_CREDENTIALS_KEY separate from APP_KEY; scheduler is
  exactly-one-replica (railway.toml) with exec PID 1.

GATE: GREEN (PASS-WITH-SUGGESTIONS) — 0 blocking findings, 4 suggestions. Phase 1 may advance. Suggestions routed to railway-infra at its discretion.

Recurring -> archivist: none new. Confirmed TS-INFRA-001 (pcntl+posix in Dockerfile), TS-INFRA-002 (no global healthcheckPath),
  TS-INFRA-003 (guarded define in config/routes — verified via live config:cache), TS-BUILD-001 (Laravel ^11.31 pinned),
  TS-BUILD-002 (3 audit advisory IDs in composer.json) all applied as the registry prescribes.
