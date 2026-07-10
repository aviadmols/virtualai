## 2026-07-10T00:00Z — Unit: Storyboard fidelity + per-frame dialogue + fal try-on/banner — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope: 3 user-requested features in one change set (35 files: 6 new, 29 modified).
  A) Storyboard character fidelity — StoryboardAssetAnalyzer + AnalyzeStoryboardAssetJob (VISION op),
     StoryboardPipeline.vars() {{reference_descriptions}}, StoryboardFrameGenerator.modelFor()
     (reference_model routing), HandlesStoryboardProjectForm (preserve+dispatch), seeder re-seed,
     StoryboardTextCaller multimodal $imageUrls, CombineStoryboardVideoJob analyses line.
  B) Per-frame dialogue — migration + StoryboardFrame.dialogue, StoryboardBuilder start/cancel/save +
     dialogueLimit, builder.blade dialogue editor, StoryboardClipGenerator {{dialogue}},
     CombineStoryboardVideoJob scene dialogue + AUTO_DIRECTIVE lip-sync, lang EN/HE.
  C) Fal try-on/banner — TryOn/BannerGenerationCaller PROVIDER_FAL + buildFalBody, FalModelCatalog
     find()/priceHintMicroUsd(), AiOperationResource.modelOptions()+ensureModelsCatalogued(),
     Create/EditAiOperation afterSave/afterCreate.

Sweeps run (changed surface):
  withoutGlobalScope (clean) · raw DB::table/statement/select on tenant table (clean) ·
  Blade::render / ->render( (clean) · uniqid/Str::uuid/random_int/microtime/time (clean) ·
  hardcoded model id in app/Domain,app/Filament added lines (clean — model ids live only in the
  seeder as DB config consts) · inline style= / arbitrary [..] in resources (clean) ·
  hardcoded 2.5/MARKUP/float money (clean — only a phpdoc @param and a string→micro-USD integer
  parse) · openrouter/sk-or-/widget_secret in resources/widget,resources/js (clean; widget untouched)

Tests run: php artisan test --filter "Storyboard|TryOnGenerationCaller|AiOperationFalCatalog"
  -d memory_limit=2G → 65 passed (244 assertions). Reported full suite 884/2945 green.

Contract verification (all PASS):
  - Money safety: no credit_ledger write added; StoryboardAssetAnalysisTest asserts
    assertDatabaseCount('credit_ledger', 0). Fal is flat-rate — FalImageClient::parseCost fails
    CLOSED (ParsedCost::unavailable) when no positive price; OperationConfig lists PROVIDER_FAL in
    isFlatRate and flatRatePriceMissing() gates early. Auto-catalogued cost_hint is fal's ADVISORY
    provider cost (pre-markup, admin-owned on Models page); null when unparseable → fails closed.
    Markup unchanged, read from config/DB. Storyboard is global/admin — no account, cannot charge.
  - AI-configurability: no model/prompt/quality/ratio literal in a service. reference_model comes
    from op params['reference_model']; DIALOGUE_PREFIX is a code const wrapping USER dialogue as
    data (like autoPrompt's data labels); FAL_IMAGE_SIZES is a provider-API mapping const. Prompts
    seeded in DB, resolved via AiOperationResolver.
  - Template safety: strtr only. Dialogue substituted as DATA via config->substituteUser (strtr),
    never Blade. Blade dialogue display uses {{ }} (htmlspecialchars) — no XSS, no {!! !!}.
  - Tenant safety: only global tables touched. StoryboardProject/Asset/Frame/FrameVersion/StepRun
    on GlobalModels::ALLOW_LIST; ai_models/ai_operations global. AnalyzeStoryboardAssetJob takes
    int $assetId on a GLOBAL model — no account bind needed (correct for the allow-list).
  - CONST-at-top / English comments / no inline CSS (.to-sb-frame__dialogue uses tokens) /
    i18n EN↔HE 1:1 (6 keys mirrored, verified) / widget untouched — all PASS.

Requested deep-checks (all verified):
  - Observer interplay in ensureModelsCatalogued: rows created WITH is_default/is_fallback set so
    AiModelObserver writes the winner THROUGH to ai_operations instead of reverting an unflagged
    row to null. Pinned by AiOperationFalCatalogTest (provider=fal, cost_hint=25000, default persists).
  - Dialogue length: server authoritative via mb_strlen (multi-byte); client maxlength mirrors
    dialogueLimit. Test uses a 26-char Hebrew line (~48 bytes) that only passes under mb_strlen,
    then rejects a 46-char line > 45 limit — meaningful, would go red if the guard were removed.
  - StoryboardTextCaller multimodal: $imageUrls defaults to [] and the old string-content path is
    taken when empty — backward compatible for scan + existing text steps.
  - analyzeMissing failure isolation: per-asset try/catch swallows a failure; pipeline notes
    "no visual analysis available". Pinned by test_an_analysis_failure_never_breaks_the_job.
  - modelFor fallback: returns the default (blind) model when reference_model is empty, equals the
    default, or is uncatalogued/inactive — degrades safely, no crash.
  - XSS: dialogue rendered via Blade {{ }} — escaped.

Blocking: none.
Suggestions:
  #S1 app/Domain/Ai/FalModelCatalog.php:104-108 — priceHintMicroUsd() grabs the FIRST "$n" in the
      pricing markdown; fal per-megapixel / "per N images" / tiered lines may not be per-image, so
      the seeded try-on cost basis (charge = cost × markup) can be off until the admin corrects it.
      Non-gating: fails closed when unparseable, admin owns the final price, matches the existing
      flat-rate admin-owned cost model. Suggest surfacing it clearly as an advisory hint for review.
  #S2 app/Filament/Platform/Resources/AiOperationResource.php:229-241 — ensureModelsCatalogued()
      calls $catalog->find($modelId) up to 3× per model (cached, so cheap); hoist into one var.
  #S3 database/migrations/2026_07_10_100000 re-runs the full seeder (clearModelFlags +
      updateOrCreate + seedModel). Deliberate per the author and matches prior seed migrations, but
      it can unseat an admin's manually-picked storyboard frame model back to the seeded default on
      deploy — awareness item, not a violation (storyboard is global admin config).
Nits:
  #N1 resources/views/filament/platform/storyboard/builder.blade.php:204 — decorative 💬 emoji
      literal; a heroicon would match the design system used by the buttons. Cosmetic.

Re-review: not required. Recommend advancing.
Recurring → archivist: none new. (Flat-rate fail-closed discipline and the fal cost-hint pattern
  continue the scars already recorded in tenant-bind-lifetime-and-flatrate-charge-2026-07-05.)
