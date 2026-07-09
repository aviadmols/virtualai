## 2026-07-09T00:00Z — Unit: Storyboard planning upgrade — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope: app/Domain/Storyboard/StoryboardFrameGenerator.php, app/Domain/Storyboard/StoryboardPipeline.php,
  app/Filament/Platform/Pages/StoryboardPipelineSettings.php, app/Jobs/CombineStoryboardVideoJob.php,
  database/seeders/StoryboardPipelineSeeder.php, database/migrations/2026_07_09_180000_upgrade_storyboard_pipeline_models_and_prompts.php,
  + 6 storyboard test files.
Sweeps run: Blade::render (clean) · credit_ledger writes in storyboard (clean) · hardcoded model id in a SERVICE (clean — all literals live in the seeder/migration, the sanctioned place; frame gen resolves via AiOperationResolver) · inline style= in storyboard views (clean).
Tests run: php artisan test --filter=Storyboard -d memory_limit=2G -> 48 passed (186 assertions). Full suite reported green by owner (853).
Contract checks:
  - AI-configurability: PASS. No model id/prompt hardcoded in a service. Frame generator + all planning steps resolve via AiOperationResolver::for(). MODEL_SUGGESTIONS is a pre-existing dropdown-hint const on a Filament page (not resolution logic).
  - Template safety: PASS. All substitution stays strtr via OperationConfig::substitute (substituteSystem([]) added to the frame path). No Blade::render.
  - Money safety: PASS. No credit_ledger write introduced; storyboard is a non-money path; tests assert credit_ledger count 0.
  - Tenant safety: PASS. Only global allow-listed rows touched (AiOperation/AiModel/scope=global Prompt with account_id NULL). No tenant-owned model added or re-scoped; autoPrompt reads project->pipeline read-only.
  - CONST-at-top / English comments / no inline CSS / i18n: PASS. New MAX_PROMPT_CHARACTERS const at top of the job; no user-facing strings added (prompt/label text is admin config, not UI copy).
  - Correctness: clearModelFlags clears is_default/is_fallback before re-flagging (fixes two stale flag rows); primary/fallback still resolve via operation.default_model/fallback_model so resolution is unaffected either way — covered by a meaningful reseed test that goes red without the fix. Frame-image system prompt prepended verbatim (placeholder-free seeded default; empty-var strtr) — covered by a start-with assertion. motion mapping is is_string()-guarded. autoPrompt null-safe for projects with no pipeline data. Migration is idempotent (updateOrCreate throughout, down() no-op) — deliberate user-approved overwrite of storyboard op prompts/models.
Blocking: none.
Suggestions:
  - S1 SUGGEST — StoryboardFrameGenerator.php:111 — frame-image system prompt is substituted with an empty var map, so any {{placeholder}} an admin later types into that prompt reaches the image model literally. Seeded default is placeholder-free and the comment documents it; consider stripping/warning on stray {{...}} or noting it in the admin UI. Non-blocking (admin-controlled, strtr not Blade — no RCE).
  - S2 SUGGEST — StoryboardPipelineSeeder.php:136 (seedClipStep) — does not call clearModelFlags() while seedTextStep/seedFrameImageStep/seedImprovePromptStep do. Harmless today (clip default resolves via operation.default_model; clip models unchanged), but clearing there too would keep the pattern uniform and survive a future clip-model swap.
  - S3 NIT — tests/Feature/Storyboard/StoryboardFoundationTest.php — the improve-prompt step's move to gemini-3.1-pro-preview is seeded but not directly asserted (the model loop covers only TEXT_STEPS); a one-line assertion would close the gap.
Nits: (folded into S3)
Re-review: not required — suggestions are discretionary for laravel-backend / ai-openrouter.
Recurring -> archivist: none (no scar class; clean unit).
