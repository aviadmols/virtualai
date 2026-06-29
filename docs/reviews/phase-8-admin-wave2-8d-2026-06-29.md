## 2026-06-29 -- Phase 8 Wave 2 (8d) -- PLATFORM (Super-Admin) Filament panel -- VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper

Scope (8d surface; merchant panel + 8c backend gaps NOT re-reviewed): Dashboard + PlatformKpiWidget + CostsVsRevenueWidget; AccountResource (+ListAccounts, ViewAccount, suspend/restore + credit-adjust); SiteResource; AiModelResource (+ConvertsCostHint); PromptResource (+PromptResolverPreview + resolver-preview.css); AiOperationResource (+ConvertsEstimatedCost); PlatformCreditLedgerResource; ActivityEventResource; PlatformPanelProvider; theme.css + shared costs-summary/resolver-preview css; lang/en|he platform.php + activity.php.

Sweeps run (Platform surface), all CLEAN:
- withoutGlobalScope inline in Platform Resources: CLEAN (hits are comments; the only real withoutGlobalScope(AccountScope) calls live in the 3 audited seams PlatformSiteQuery / PlatformCreditLedgerQuery / PlatformActivityQuery, each guarded by PlatformGuard::assert before the bypass).
- raw DB::table/select/statement in Platform: CLEAN (the one cross-account aggregate is CostsMetricsBuilder, a sums/counts-only DB::table on credit_ledger, no row/PII -- sanctioned).
- Blade::render / render / eval near prompt/template: CLEAN (docblock reference only; substitution is strtr via OperationPreview::renderUserPrompt, echoed through Blade auto-escape).
- hardcoded model ids in Platform: CLEAN. inline style= : CLEAN. Tailwind arbitrary + hex/px literals: CLEAN. physical CSS props: CLEAN (logical only; numeric/currency alignEnd). secrets rendered in platform UI: CLEAN (ActivityEvent subject = type#id, no payload column).

Tests run: php artisan test = 390 passed (1231 assertions), 55.87s. Boot check: app boots; both panels register; platform routes resolve.

G6 tenant-safety (RELEASE BLOCKER) -- PASS:
- SiteResource::getEloquentQuery -> PlatformSiteQuery::withAccount (audited seam). [SiteResource.php:106-109]
- PlatformCreditLedgerResource::getEloquentQuery -> PlatformCreditLedgerQuery::withAccount. [PlatformCreditLedgerResource.php:137-140]
- ActivityEventResource::getEloquentQuery -> PlatformActivityQuery::withAccount. [ActivityEventResource.php:126-129]
- PromptResolverPreview site picker reads via PlatformSiteQuery (audited). [PromptResolverPreview.php:107,149]
- Account/AiModel/AiOperation/Prompt = allow-list-global -> direct read OK (Prompt allow-listed per TS-OPENROUTER-002; panel is super-admin-only).
- PlatformGuard::assert fires BEFORE every withoutGlobalScope; resolves super-admin from Auth only, never the request body. [PlatformGuard.php:26-33]
- Panel gate intact: User::canAccessPanel returns isSuperAdmin for platform, inverse for merchant; NO Platform resource overrides canAccessPanel. [User.php:99-104]
- Seam tests meaningful (not theatre): super-admin reads across accounts; merchant/unauthenticated/bound-tenant FAIL LOUD with PlatformAccessRequiredException; all three seams share one guard. [PlatformReadSeamsTest.php]

G9 template-safety (RELEASE BLOCKER) -- PASS:
- Sample substitution is OperationPreview::renderUserPrompt = strtr only, echoed via Blade auto-escape; an injected placeholder/script/php directive renders verbatim as escaped text. [OperationPreview.php:127-136; resolver-preview.blade.php:126]
- Preview makes NO HTTP call and NO DB write (renders the 8a AiOperationResolver::preview DTO; computed-not-stored, never serializes a model into Livewire state). [PromptResolverPreview.php:74-100,146-157]

Money-safety -- PASS (with 1 carried SUGGESTION):
- Credit-adjust routes through PlatformCreditAdjustment::apply -> CreditLedgerService::adjustment (append-only row, row lock, idempotency pre-check, balance_after stamped) -- never a bare balance write. [AccountResource.php:234-242; PlatformCreditAdjustment.php:50-75]
- USD to micro-USD folded once via CreditMath::usdToMicro -> integer column; no float reaches a money column (adjust action + ConvertsCostHint + ConvertsEstimatedCost). [AccountResource.php:235; ConvertsCostHint.php:31-33; ConvertsEstimatedCost.php:31-33]
- Downward adjust floored at balance 0 (never negative). suspend/restore are idempotent and write account_suspended/account_restored activity events. [PlatformCreditAdjustment.php:82-93; AccountResource.php:166-198]

No-invented-status -- PASS: ledger type badge via StatusBadge (ledger map) [PlatformCreditLedgerResource.php:92-93]; activity kind via activity.kind.* with humanised fallback [ActivityEventResource.php:144-150]; Account active/suspended via the model own enum + a local STATUS_TONES map (acceptable -- mirrors the 8c Site setup-state decision) [AccountResource.php:53-56].

i18n 1:1 -- PASS: platform.php en 239 vs he 239 (0 drift both directions); activity.php en 20 vs he 20 (0 drift both directions). EN-leak heuristic: only logs.actor.webhook = Webhook (a proper technical noun, identical in HE -- acceptable, informational).

Design system -- PASS: zero inline style, zero Tailwind arbitrary, logical props only, no hex/px outside token files; resolver-preview + costs gauge fully token-backed (bucketed fill modifier classes p0..p100, no inline width). CONST-at-top present in every reviewed file.

Secrets -- PASS: ActivityEvent subject renders as class_basename#id only (details JSON not surfaced); no widget_secret / OpenRouter key rendered anywhere on the platform surface.

Blocking: (none)

Suggestions:
- S1 [SUGGEST, carried from 8c] AccountResource.php:224-242 + PlatformCreditAdjustment.php:58 -- credit-adjust idempotency anchor. The reference field is OPTIONAL (no required rule); when blank, apply mints a fresh Str::uuid PER CALL, so a double-submit / re-render of the modal yields TWO distinct idempotency keys -> TWO adjustment rows -> a DOUBLE-ADJUST. Confirmed by the existing test test_a_missing_reference_falls_back_to_a_unique_key_each_call (asserts 2 rows). Filament disables the submit on wire:loading, which narrows but does not close the race (rapid double-click before the Livewire dispatch, or a replay). Severity: SUGGEST (escalating) -- money-safety but an admin-only, self-correctable, non-isolation path; not BLOCK. Fix: derive a STABLE idempotency anchor when the reference is blank (fold the Account id + the action mount/component id, or require the reference) so a double-submit collapses to one row.

Nits:
- N1 [NIT] PromptResolverPreview.php:23-24 docblock says the substituted text is shown in an isolated iframe srcdoc; the blade actually echoes it through a pre element with Blade auto-escape (equally RCE-safe). Doc/comment drift only -- correct the comment to match the implementation.

Re-review: not required to advance. S1 routed to admin-design-system (action wiring) with laravel-backend / saas-credits-billing consult on the anchor shape; N1 doc fix to admin-design-system. Neither gates the 8d phase.

Recurring -> archivist: credit-adjust double-submit idempotency anchor -- carried from the 8c gate and re-confirmed in 8d (2nd appearance of the same class). Registry entry: an admin form money action needs a stable idempotency anchor, not a per-render UUID.
