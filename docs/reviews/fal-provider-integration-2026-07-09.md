
## 2026-07-09T00:00Z — Unit: fal.ai provider integration (images + video) — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope (27 files): app/Domain/Ai/FalImageClient.php · FalVideoClient.php · FalModelCatalog.php ·
Concerns/EncodesImageDataUris.php · Contracts/ImageGenerationProvider.php · OperationConfig.php ·
ProviderRouter.php · VideoProviderRouter.php · app/Domain/Platform/PlatformSettings.php ·
app/Domain/Playground/PlaygroundImageRunner.php · app/Domain/Storyboard/StoryboardFrameGenerator.php ·
app/Filament/Platform/{Pages/ModelPlayground,Pages/Settings,Pages/StoryboardPipelineSettings,
Resources/AiModelResource,Widgets/ProviderCostsWidget} · app/Models/{AiModel,PlaygroundRun} ·
config/services.php · database/seeders/StoryboardPipelineSeeder.php ·
database/migrations/2026_07_09_190000_switch_storyboard_frame_image_to_fal_krea.php ·
lang/en+he/platform.php · tests/Feature/Ai/{FalImageClientTest,FalVideoClientTest} ·
tests/Feature/Storyboard/{StoryboardClipTest,StoryboardFoundationTest,StoryboardFrameGenerationTest}

Sweeps run:
  Blade::render (clean — only "NEVER Blade::render" comments) ·
  withoutGlobalScope (clean) · DB::table/DB::statement on new files (clean) ·
  fal/OpenRouter key in resources/widget|js (clean — no secret in browser) ·
  inline CSS (n/a — no blade touched) ·
  hardcoded model id in a service (only the no-spend PROBE_MODEL const + aspect->enum map +
    sanctioned seeder/migration/UI-suggestion consts) ·
  EN/HE key parity (settings.fal 9/9 mirrored, costs.providers.fal present both) ·
  secret write-only (Settings::mount hydrates VISIBLE_SETTINGS only; fal_api_key never hydrated)

Tests run: php artisan test (-d memory_limit=2G) — FalImageClientTest, FalVideoClientTest,
  StoryboardClipTest, StoryboardFrameGenerationTest, StoryboardFoundationTest →
  36 passed / 140 assertions. Meaningful (parseCost fail-closed on null/0, data-URI inlining,
  composite task-id round-trip, catalog degrade-to-[], credit_ledger count 0 on every storyboard path).

Contract gates (all PASS):
  - fal key never reaches the browser: server-side clients only; Settings secretField is write-only
    (mount() hydrates only VISIBLE_SETTINGS, fal_api_key excluded); no fal key in widget/js. PASS.
  - Money safety (flat-rate fails closed): FalImageClient::parseCost returns unavailable without a
    positive price (FalImageClient.php:258-267); OperationConfig::isFlatRate now includes fal so
    flatRatePriceMissing() fails EARLY (OperationConfig.php:107-116); AiModelResource::isFlatRateProvider
    requires a positive per-image hint. Storyboard never charges — every touched test asserts
    assertDatabaseCount('credit_ledger', 0). PASS.
  - AI-configurability: no generation model id hardcoded in a service; PROBE_MODEL is a no-spend
    key-probe const (GET on a status route, never a POST/generation). Generation ids come from
    the DB via the resolver. Seeder/migration/UI-suggestion consts are sanctioned. PASS.
  - Template safety (no Blade::render), tenant safety (only global allow-list config tables:
    ai_operations/ai_models/platform settings; no new tenant-owned model), CONST-at-top,
    English-only comments, no inline CSS, i18n EN/HE 1:1. PASS.
  - Correctness deep-checks: composite video task-id round-trip (submit "{model}|{id}" -> stored
    video_task_id -> strrpos split; model path has no '|') CORRECT & tested; data-URI trait bounds
    (30s timeout, 12 MiB cap) OK; aspectRatio param default-null backward-compat CONFIRMED (the two
    other run() callers omit it). CORRECT.

Blocking: NONE.

Suggestions:
  S1 FalModelCatalog.php:74-81 — a failed catalog fetch caches [] for the FULL 1h TTL
     (CACHE_TTL_SECONDS=3600), poisoning the admin picker for up to an hour after a transient
     outage; the docblock "caches [] briefly" (lines 68-70) is inaccurate. Not a safety issue
     (picker degrades to pinned suggestions + manual id entry). Fix: only cache non-empty results,
     or cache a failed fetch with a short TTL; correct the "briefly" comment.
  S2 PlaygroundImageRunner.php:155-167 (falBody) + FalImageClient::inlineInputImages — the DEFAULT
     frame-image model fal-ai/krea-2/turbo is TEXT-TO-IMAGE only, yet falBody unconditionally sends
     image_url + image_urls (downloaded + base64-inlined) on the documented assumption "fal ignores
     undeclared fields." The frame test fakes the submit (200), so the assumption is UNVERIFIED
     against live fal; if fal validates strictly (422 on unknown fields) the default storyboard
     frame step breaks in prod, and every frame wastes bandwidth inlining images the model ignores.
     Storyboard-only (no charge) -> SUGGESTION, not a blocker. Fix: verify against live fal, or omit
     the image fields for known text-to-image models (capability gate).
  S3 FalImageClient.php:51-53 — the poll-budget comment ("2s x 40 ~= 80s ... under the queued
     worker's timeout") counts only sleep, not per-request HTTP latency (each status GET is bounded
     by services.fal.timeout, default 80s). Worst-case wall time = 80s sleep + up to 41 request
     timeouts. Confirm the frame/generation worker timeout exceeds the realistic budget, or use a
     shorter status-poll HTTP timeout. Frame path fails closed on timeout (no charge).

Nits:
  N1 Concerns/EncodesImageDataUris.php:18-23 — the CONSTANTS block uses `private static` properties
     instead of `const`; PHP 8.4 supports trait constants, so `private const` matches CONST-at-top
     more literally.

Operational note (-> troubleshooting-archivist / deploy): the storyboard frame-image DEFAULT now
  requires FAL_API_KEY set on Railway; without it, frames fail closed (terminal FAILED, no charge).
  Same class as the known "placeholder key -> silent failure" scar — verify the fal key + run
  "Test connection" before this migration reaches prod.

Re-review: NOT required to advance (no blockers). S1-S3/N1 are the owner's (ai-openrouter) discretion.
