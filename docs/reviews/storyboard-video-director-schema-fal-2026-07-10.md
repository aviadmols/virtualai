## 2026-07-10T00:00:00Z — Unit: reference-mode video quality (schema-driven fal params + Director module) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope (uncommitted working tree):
- NEW app/Domain/Ai/FalEndpointSchema.php
- NEW app/Domain/Storyboard/StoryboardVideoDirector.php
- NEW database/migrations/2026_07_10_130000_seed_storyboard_video_director.php
- NEW tests/Feature/Ai/FalEndpointSchemaTest.php
- app/Domain/Ai/FalVideoClient.php · app/Jobs/CombineStoryboardVideoJob.php
- app/Domain/Storyboard/StoryboardStep.php · app/Models/AiOperation.php
- app/Filament/Platform/Pages/StoryboardPipelineSettings.php
- database/seeders/StoryboardPipelineSeeder.php
- lang/en/platform.php · lang/he/platform.php
- tests: FalVideoClientTest, StoryboardCombineVideoTest, StoryboardPipelineSettingsTest

Sweeps run:
- hardcoded-model-in-services (FalEndpointSchema, StoryboardVideoDirector, FalVideoClient, CombineStoryboardVideoJob) → CLEAN (director model resolves from the DB op via AiOperationResolver::for(); model ids live only in the seeder = DB defaults, allowed)
- Blade::render in app/Domain/Storyboard → CLEAN (substitution runs through OperationConfig::substitute → strtr; RCE-safe)
- credit_ledger / CreditGate / charge writes in changed files → CLEAN (storyboard never charges; tests assert credit_ledger count 0)
- inline style= in changed resources → CLEAN (no view changes; lang strings only)
- i18n EN/HE mirror → CLEAN (storyboard_video_director present in both lang/en/platform.php:269 and lang/he/platform.php:268; the two combine_* help strings updated 1:1)

Tests run: full suite reported 902/2992 green + --filter=Fal + --filter=Storyboard green (by the submitting agent). Assertions independently read and judged MEANINGFUL:
- FalVideoClientTest: asserts duration===15 (int type preserved), resolution==='720p' (nearest of 720/1080 for a 480 request), aspect_ratio==='16:9' (exact enum), image_urls trimmed to maxItems=2, image_url ABSENT (schema declares image_urls only), prompt truncated to maxLength=30 — goes red if the clamp/type logic breaks.
- StoryboardCombineVideoTest: manual→verbatim (prompt_source='manual', no OpenRouter chat call), director success (DIRECTOR-MARKER present in fal/atlas submit body + prompt_source='director'), deleted-op fallback (prompt_source='auto'), fal duration clamp e2e (duration sent ==='10', requested_seconds=120, effective_seconds=10). Every case asserts credit_ledger count 0.
- StoryboardPipelineSettingsTest: director section renders + Test button routes the on-demand text op to the real text caller.

Contract findings:
- CONST-at-top: PASS (FalEndpointSchema.php:22, StoryboardVideoDirector.php:26).
- No hardcoded model id / prompt / quality / ratio in a service: PASS.
- strtr-not-Blade for all substitution: PASS.
- Storyboard NEVER charges: PASS (no ledger surface touched).
- Tenant-safety: N/A — platform-admin storyboard domain; no new models, no account_id-scoped query touched.
- English-only comments: PASS. i18n EN/HE 1:1: PASS. No inline CSS: PASS.
- Fail-open schema fetch cannot break a submission: PASS (ConnectionException→[]; non-successful/non-array→[]; empty properties→legacy prompt+images body; empty schema cached only 120s so a transient outage can't poison the 1h TTL).
- Fal client change cannot regress BytePlus/AtlasCloud: PASS (separate clients; job effectiveSeconds() guards on PROVIDER_FAL; schema-shaping lives only in FalVideoClient::submitTask).
- Clamp logic sanity: PASS — no off-by-one. clampDuration returns the largest enum ≤ request else the smallest (largestNotAbove); enum type emitted verbatim (int 15 / string "10" / "8s"); clampResolution nearest-by-abs with ties→smaller; matchAspectRatio exact-enum-only; effectiveDuration strips non-digits → int; data_get path 'content.application/json.schema' resolves correctly (slash is not a dot separator); resolveRef via basename($ref).
- Migration idempotency: PASS — up() calls the public seedVideoDirectorStep() (all updateOrCreate); clearModelFlags is scoped to operation_key=storyboard_video_director ONLY, so the admin's CLIP-model choice and other steps' prompt edits are untouched; down() is an intentional no-op (config-only addition).

Blocking: NONE.
Suggestions:
- #1 SUGGEST — FalEndpointSchema.php:104-111 (shapeBody): when a fetched schema exposes neither `image_url` nor `image_urls` (a model that names its reference key differently, e.g. `image`/`frames`), the inlined reference frames are silently dropped and a reference-to-video model would render WITHOUT the storyboard frames — a quality regression, not a safety one (never charges). Consider logging when a present schema yields no recognized image key, or attaching images under the legacy key as a last resort. Non-gating.
Nits:
- effectiveSeconds() and submitTask() each call inputSchema($model) — a second lookup, but it is a 1h cache hit; harmless.

Re-review: not required (no blockers). Gate may flip GREEN.
Recurring → archivist: none new. (Reinforces the existing scar "verify fal model ids / per-model enums against the live catalog" — now handled structurally by the endpoint schema instead of a hardcoded map.)
