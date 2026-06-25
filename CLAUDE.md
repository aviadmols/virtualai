# CLAUDE.md — Tray On (AI Virtual Try-On SaaS)

> Project memory for this repo. Read this first. The locked architecture lives in
> [ARCHITECTURE.md](ARCHITECTURE.md). Work is driven by the agent team in
> `.claude/agents/` — invoke `trayon-orchestrator` first.

## What this is

**Tray On** is a multi-tenant SaaS that shows a shopper an AI-generated image of
how a product looks *on them* before they add it to cart. A merchant pastes a
product-page URL; Tray On's AI scans it (product data, variants, dimensions, page
selectors). The merchant installs a small JS widget that injects a **Tray On**
button under "Add to cart". A shopper uploads a photo + height; Tray On generates
a realistic try-on of the selected variant via **OpenRouter**, lets them add that
exact variant to cart, and keeps a small on-site gallery of their generations.

Three pillars — **none may be dropped**:

1. **PDP ingestion** — AI scan of a product URL + manual confirm/correct of every
   field and selector.
2. **Try-on generation** — shopper photo + height → realistic variant try-on →
   result screen (regenerate / change photo / add-to-cart / back) + gallery.
3. **Credits, leads & control plane** — per-account credit ledger (2.5× markup),
   per-site lead capture, and a DB-managed Super-Admin control plane for models,
   prompts, costs, accounts, sites, and credits.

## The agent team (`.claude/agents/`)

Invoke `trayon-orchestrator` first; it enforces the handoff order and phase gates.

`trayon-orchestrator` → `railway-infra` → `laravel-backend` →
`ai-openrouter` → `pdp-scanner` → `saas-credits-billing` →
`product-ux-architect` (parallel from start) → `widget-embed` →
`admin-design-system`.

`code-review-gatekeeper` reviews **every** unit of code and runs at every phase
gate. It only reports findings (BLOCKING / SUGGESTION); the implementing agent
applies the fix. A BLOCKING finding stops a phase from advancing. Append-only
reviews live in `docs/reviews/`.

`troubleshooting-archivist` keeps the shared known-issues registry at
[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md). Every agent **consults** it
before building in its area and **records** any non-trivial blocker + the
verified fix after resolving it — so the same problem never costs time twice and
future sessions go smoothly. The orchestrator consults it at the start of each
phase and records at each gate.

| Agent | Owns |
|---|---|
| `trayon-orchestrator` | Roadmap, phase gates, questionnaire, conflict resolution, `TodoWrite`. Plans and routes; writes no feature code. |
| `railway-infra` | Railway web/worker/scheduler, Horizon, Postgres/Redis, queues, rate-limiting, env contract, predeploy guard, CDN/media wiring. |
| `laravel-backend` | Tenancy core (`Account`/`Site`/`BelongsToAccount`/`Tenant`), credit ledger + reservations, scan + generation pipelines, jobs/scheduler, leads backend, storage, retention, activity timeline. |
| `ai-openrouter` | OpenRouter client, `AiOperationResolver` (model/prompt resolution), image generation + scan extraction calls, cost parsing, model fallback, retries. |
| `pdp-scanner` | PDP fetch/render strategy, AI extraction → structured product + selectors, confidence scoring, the confirm/correct UX contract. |
| `saas-credits-billing` | Markup math, credit purchase rail (`CreditPaymentProvider`), usage limits/plan gates, the lead gate, privacy/GDPR + retention policy, **tenant-isolation audit (release blocker)**. |
| `product-ux-architect` | UX spec, design-token table, component inventory, i18n catalog, per-feature Definition of Done. Runs parallel from the start. |
| `widget-embed` | The storefront JS widget: PDP detection, variant sync, button injection, the modal (upload/height/consent), result screen, gallery slider, add-to-cart. |
| `admin-design-system` | The two Filament panels, design system (tokens → CSS vars), premium modal/widget styling, EN/HE RTL. Last in the chain. |

## Local toolchain (this machine)

PHP 8.4 (Herd): `C:\Users\user\.config\herd\bin\php84\php.exe`
Composer: `<php84> C:\Users\user\.config\herd\bin\composer.phar`
(PHP/Composer are NOT on PATH — use these absolute paths in Bash.)

## Non-negotiable conventions

- **CONST-at-top.** Every file opens with its constants block (PHP: a
  `// === CONSTANTS ===` block of `const`; Blade/CSS/JS: a token-reference
  block). No magic strings or numbers scattered mid-file — route names, status
  maps, model ids, limits, queue names, KPI keys all live as consts at the top.
- **Clean, short, English-only comments.** Small single-responsibility classes.
  Comments only when they earn their place, and only in English.
- **No inline CSS in admin/widget UI.** Tokens → CSS custom properties →
  component classes only. No `style="…"`, no Tailwind arbitrary values
  (`bg-[#fff]`, `p-[13px]`). **Exception:** email-template HTML requires inline
  CSS (clients strip `<style>`).
- **Template safety.** Merchant/admin-edited prompt and email text is substituted
  with **`strtr()`, NEVER `Blade::render()`** (RCE prevention). Preview merchant
  HTML only via isolated `iframe srcdoc` + `htmlspecialchars`.
- **Tenant-safety is a RELEASE BLOCKER.** Every tenant-owned model has
  `account_id` + the `BelongsToAccount` trait (global scope). No
  `withoutGlobalScopes()` in product code (only audited platform-admin
  services). **Every queued job receives `account_id` explicitly** — never infer
  the account from global state, session, domain, or config.
- **Money safety.** No credit charge without a `credit_ledger` row. Reserve
  before the OpenRouter call; debit **only on success**, release on failure.
  Every charge has a deterministic idempotency key (ARCHITECTURE.md). The markup
  multiplier is read from config/DB, never hardcoded at a call site.
- **AI is configurable, not hardcoded.** No model id, prompt, quality, or aspect
  ratio is literal in a service. Ask `AiOperationResolver`. Super-Admin changes
  behavior from the DB without a redeploy.
- **The OpenRouter key never reaches the browser.** All model calls are
  server-side. The widget authenticates by `site_key` + `Origin` allow-list.
- **State transitions.** Only canonical transitions (ARCHITECTURE.md) are legal;
  a guarded `transitionTo()` rejects the rest and writes an activity event.
- **i18n.** English is the default; all user-facing strings go through `__()`
  with keys in `lang/en/*.php`; `lang/he/*.php` mirrors them 1:1; build
  RTL-aware (logical CSS properties only).
- **Widget weight is a feature.** Keep the storefront script lean and lazy; never
  block the host page's render, SEO, LCP, or CLS.

## Status

Scaffold pass in progress: contract docs (`ARCHITECTURE.md`, this file) + the
agent team are being laid down by `trayon-orchestrator`. The Laravel skeleton,
the engine, and the screens are executed by the agents across the phased roadmap.
