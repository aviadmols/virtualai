## 2026-07-12 — Pre-deploy review: Kling provider + Shopify Phase 1 + Storyboard Director — VERDICT: BLOCKED (1 blocking)
Reviewer: code-review-gatekeeper
Scope: full uncommitted working tree (33 modified + 28 untracked files) — app/Domain/Ai/Kling*, Concerns/SignsKlingRequests, app/Domain/Shopify/*, app/Http/Shopify/*, app/Models/Shopify*, config/{services,shopify,trayon,horizon}.php, 4 migrations, app/Domain/Storyboard/{StoryboardPromptComposer,StoryboardTimingPlan,StoryboardPipeline,StoryboardStep}, StoryboardPipelineSeeder, Filament platform pages, lang/{en,he}/platform.php, tests.

Sweeps run:
- withoutGlobalScopes outside audited PlatformAdmin seams — CLEAN (no new hits)
- raw DB:: on tenant tables — 1 new hit (ShopifyShopRouter:37), matches the audited SiteRouter/PurchaseRouter pre-bind routing exception (returns only account_id) — ACCEPTED
- jobs missing explicit account_id — CLEAN (dispatcher passes int $accountId; recovery job is pre-bind platform housekeeping)
- hardcoded model id / prompt / quality / ratio at a call site — CLEAN (Kling ids are admin picker suggestions only; every call site takes $model from OperationConfig/AiOperationResolver)
- Blade::render on merchant/admin templates — CLEAN (strtr everywhere; system + user prompts both substituted)
- secrets in browser/widget code — CLEAN (resources/widget has no key material)
- secrets in logs — CLEAN (maskedAccessKey only; the Kling SECRET key is never logged)
- inline style= / Tailwind arbitrary values (non-email) — CLEAN
- lang/en <-> lang/he key mirror — CLEAN (1:1 across all files, incl. the new platform.settings.kling.* and storyboard step labels)
- removed-constant references (AiOperation::KEY_STORYBOARD_{READ_IDEA,GENRE,CHARACTERS,VISUAL_BIBLE}, StoryboardStep::PIPELINE_KEY) — CLEAN (zero remaining PHP references)
- php -l on every changed/new file — CLEAN

Tests run: php -d memory_limit=1024M vendor/bin/phpunit --testsuite=Unit,Feature -> 942 tests, 3117 assertions, 1 FAILURE.

Blocking:
- #1 KlingVideoClient::DURATIONS (app/Domain/Ai/KlingVideoClient.php:52) contradicts its own test (tests/Feature/Ai/KlingVideoClientTest.php:96-97) and the suite is RED. Unverified provider enum = the Seedream fabricated-id scar class.

Suggestions: #2 SignsKlingRequests base_url guard declared (HOST_SUFFIX/REQUIRED_SCHEME) but never implemented · #3 KlingCatalog::isUnsupported() dead code · #4 dead const HTTP_RATE_LIMITED · #5 StoryboardPipeline frame/plan length mismatch (end($plan) fallback) · #6 StoryboardTimingPlan frameCount=0 edge · #7 RecoverStuckShopifyWebhooksJob not ShouldBeUnique · #8 shopify_webhook_receipts.payload holds unencrypted PII in a globally-unscoped table (no account_id stamped post-routing) · #9 PlatformSettings Shopify comment claims a UI that does not exist · #10 sites.platform column unused / not fillable / no model consts.

Re-review: required (ai-openrouter for #1). Tenant-safety and money-safety: PASS on their own evidence (Shopify isolation is fail-closed + tested; Kling parseCost is the audited flat-rate rail — unavailable never charges).
Recurring -> archivist: (a) provider enum/model-id lists asserted without a verified source (2nd time after Seedream); (b) a security guard documented in a comment + constants but never implemented in the code path.

---

## 2026-07-12 (later) — PHASE GATE: Shopify Phase 2 (OAuth both origins · webhook intake · uninstall · Theme App Extension · merchant connect screen) + the Kling static-API-key auth change — VERDICT: BLOCKED (2 blocking)
Reviewer: code-review-gatekeeper
Scope: app/Domain/Shopify/{Auth/*,Api/*,Webhooks/*,ShopifyCredentials,ShopifyLogContext}, app/Http/Shopify/{Controllers/*,ShopifyShopRouter}, app/Models/{ShopifyPendingInstall,ShopifyConnection,ShopifyWebhookReceipt,Site}, app/Support/GlobalModels, routes/shopify.php, config/shopify.php, bootstrap/app.php, 3 shopify migrations + factories, app/Filament/Merchant/Pages/ShopifyStore.php + blade + shopify-connect.css + merchant/theme.css, lang/{en,he}/shopify.php, shopify/** (app.toml + trayon-widget extension), app/Domain/Ai/Concerns/SignsKlingRequests.php, config/services.php (kling), PlatformSettings, Filament/Platform/Pages/Settings.php, lang/{en,he}/platform.php (kling), tests/Feature/{Shopify/*,Filament/Merchant/ShopifyStorePageTest,Ai/Kling*}, tests/Unit/Ai/KlingJwtTest.

Sweeps run:
- withoutGlobalScopes outside the audited Platform* seams — CLEAN (no new hits; ShopifyStore.php only mentions it in a comment)
- raw DB:: on a tenant table — 1 hit (ShopifyShopRouter.php:39), the audited pre-bind routing lookup (returns only the integer account_id, never a row/token) — ACCEPTED, same class as SiteRouter/PurchaseRouter
- jobs missing an explicit account_id — CLEAN (HandleShopifyWebhookJob + RegisterShopifyWebhooksJob both extend TenantAwareJob with int $accountId; the dispatcher passes the router-resolved id)
- secrets in logs — CLEAN (no token/client_secret/kling secret in any Log:: call; ShopifyLogContext carries correlation/webhook/topic/shop only; GraphQL token is header-only)
- secrets in browser/extension code — CLEAN (the theme extension carries data-site-key only; no client_secret, no access token, no OpenRouter key)
- Blade::render on merchant/admin-edited text — CLEAN (strtr everywhere)
- inline style= / Tailwind arbitrary values (non-email) — CLEAN
- RTL: physical-direction CSS in shopify-connect.css — CLEAN (logical properties + 22 var(--…) token reads, zero literal colors)
- lang/en <-> lang/he mirror — CLEAN 1:1 (shopify.php 39/39; platform.php 650/650 incl. the kling block)
- CONST-at-top — CLEAN on every new PHP/Blade/CSS/JS/liquid file

Tests run: "C:/Users/user/.config/herd/bin/php84/php.exe" artisan test -> 988 passed, 3315 assertions, 0 failures (the prior KlingVideoClient::DURATIONS blocker is fixed; the suite is GREEN).

Blocking:
- #1 OAuth state is NOT bound to the initiating browser session (ShopifyOAuthState.php:94 writes the nonce to the GLOBAL cache; OAuthController.php:204-218 trusts the state's account_id and never compares it to Auth::user()->account_id). Any browser presenting a valid state completes the connect for the account named inside it -> store-attachment CSRF: a Tray On merchant mints a state for victim.myshopify.com against their own site, phishes the victim's store admin with the genuine Shopify grant URL, and the victim's store + its OFFLINE token land under the ATTACKER's account (assertShopIsClaimableBy sees an unknown shop and allows it). ShopifyOAuthConnectTest.php:93 is the proof: the happy-path callback runs with NO actingAs / no session and still persists the connection. Owner: laravel-backend.
- #2 SignsKlingRequests.php:49-51 declares HOST_SUFFIX/REQUIRED_SCHEME ("a mis-typed or hostile base_url must never receive the platform's Kling credential") but klingRequest():68-71 passes $baseUrl straight to ->baseUrl() with Authorization: Bearer <platform Kling key> attached. The constants are dead; $baseUrl is fed from DB-managed ai_models.base_url (KlingVideoClient::submitTask:126, pollTask:182). The twin guard IS implemented at BytePlusImageClient.php:262-295. SECOND consecutive review of this finding (prior log SUGGESTION #2, recurring class (b)). Owner: ai-openrouter.

Suggestions: #3 expired shopify_pending_installs are never purged (ShopifyPendingInstall.php:31 TTL 60min; routes/console.php:22 prunes receipts only) — an abandoned install parks a LIVE offline token at rest forever · #4 shopify-store.blade.php:14 pulls the whole DECRYPTED credentials array into view scope while the file header claims "the access token NEVER reaches a Blade" (not rendered, not Livewire-serialized — but expose scopes()/apiVersion() accessors instead) · #5 the allow-list audit is circular (ShopifyInstallNewShopTest.php:56-61 + SiteKeyAndAllowListTest.php:72-84 assert membership of the constant in itself; GlobalModels.php:13-15 promises a reflective "un-scoped models === ALLOW_LIST" sweep that does not exist) — owner saas-credits-billing · #6 shopify_webhook_receipts.payload holds unencrypted customer PII for 14 days in a globally-unscoped table, now that the GDPR topics are live (re-raised from #8) · #7 OAuthController::install():141 verifies the hmac only when one is present — no open redirect (the shop regex holds) but an unauthenticated caller can mint unlimited state nonces into the cache · #8 the webhook throttle keys by IP (CreditsServiceProvider.php:52-53); Shopify delivers from a shared pool — key by X-Shopify-Shop-Domain · #9 ShopifyInstaller::connect():88-100 has no lockForUpdate on shop_domain; a concurrent race hits the unique index as a 500 instead of the typed conflict · #10 trayon.liquid:25 outputs the merchant-set script_src raw into src= (self-XSS only; add | escape).

Nits: #11 SignsKlingRequests.php:43 HTTP_RATE_LIMITED still dead (prior #4).

What PASSED on its own evidence: raw-body webhook HMAC (getContent(), base64, hash_equals, fail-closed 401 with NO receipt row) · webhook dedupe on webhook_id (unique index + a QueryException race catch, at-most-once) · no work inline (verify -> receipt -> dispatch -> 200) · every dispatched job carries an explicit account_id resolved pre-bind · the shop-domain regex (anchored *.myshopify.com; a non-myshopify shop provably never reaches the token exchange — Http::assertNothingSent) · the pending-install record (encrypted token at rest, sha256-HASHED single-use claim token held in the SESSION not a URL, expiry enforced, DELETED on consumption, a shop owned by another account cannot be claimed) · the merchant page reads the connection through the Filament shop tenant + BelongsToAccount, and the webhook-health counters are keyed by this connection's globally-unique shop_domain (proven it cannot count another shop's receipts) · the guarded installed<->uninstalled transition wipes credentials + writes the activity event · secrets never logged/serialized/rendered · EN/HE 1:1, zero inline CSS, CONST-at-top.

Re-review: REQUIRED (laravel-backend #1, ai-openrouter #2). Phase 2 does not advance until #1 and #2 are fixed and re-reviewed.
Recurring -> archivist: (b) "a security guard documented in a comment + constants but never implemented in the code path" — 2nd occurrence (SignsKlingRequests base_url), now escalated from SUGGESTION to BLOCKING; (c) NEW class: "an OAuth/redirect state nonce that is signed + single-use but not bound to the initiating browser session".

---

## 2026-07-12 (re-review) — Shopify Phase 2 + Kling auth — clears the BLOCKED verdict above — VERDICT: PASS-WITH-SUGGESTIONS
Reviewer: code-review-gatekeeper
Scope: ONLY the two blocking surfaces — app/Domain/Shopify/Auth/ShopifyOAuthState.php, app/Http/Shopify/Controllers/OAuthController.php, app/Domain/Ai/Concerns/SignsKlingRequests.php, and the new/updated tests (ShopifyOAuthConnectTest, ShopifyInstallNewShopTest, KlingVideoClientTest).

#1 STORE THEFT — CLEARED. Verified by reading, not by report. Two independent walls, both fail-closed:
 - BROWSER BINDING (ShopifyOAuthState.php:99 / :154): the single-use nonce is now put into the ISSUING session and PULLED at verify with a strict `!== true` (a missing key fails closed). The global-cache dependency is GONE (re-swept: zero CacheRepository/NONCE_CACHE hits under app/Domain/Shopify). A state minted in the attacker's browser is dead in the victim's.
 - ACCOUNT WALL (OAuthController.php:203-210): for FLOW_CONNECT_EXISTING_SITE the caller must BE the account the state names; `Auth::user()?->account_id ?? 0` makes a guest fail closed too. Critically, this wall sits BEFORE exchangeCode() — a tampered state never reaches a token exchange (the tests pin it with Http::assertNothingSent()).
 Tests are MEANINGFUL, not theatre — they assert the ABSENCE that matters: 403 + no connection + site not flipped + Http::assertNothingSent (ShopifyOAuthConnectTest.php:228-250, :252-270), and for the install flow, which has no account wall and so isolates the browser binding: 403 + assertSessionMissing(claim token) + 0 pending installs + nothing exchanged (ShopifyInstallNewShopTest.php:193-212). Each can only pass with the guard present.

#2 KLING CREDENTIAL TO AN UNVALIDATED HOST — CLEARED. SignsKlingRequests.php:77-101 now implements host()/sanitizeBaseUrl() as the exact twin of BytePlusImageClient::host():262-295 (parse_url -> scheme must be https AND host === klingai.com or *.klingai.com, else DROP and fall back to the configured region host); klingRequest():69 calls host($baseUrl). HOST_SUFFIX/REQUIRED_SCHEME are now live (:47/:49); the dead HTTP_RATE_LIMITED const is removed (NIT 11 closed). Tests pin both directions: a hostile base_url never receives the bearer (KlingVideoClientTest.php:135-146 — assertNotSent to evil.example.com AND assertSent to the config host) and the legitimate api-beijing region override still routes (:148+) — the guard neither leaks nor over-blocks.

Sweeps re-run on the fixed surfaces: state-in-global-cache CLEAN · Kling baseUrl-unguarded CLEAN · dead consts CLEAN.
Tests run: "C:/Users/user/.config/herd/bin/php84/php.exe" artisan test -> 993 passed, 3332 assertions, 0 failures (up from 988; +5 = the new guard tests).

NEW SUGGESTION (a direct consequence of the browser-binding fix): #12 SESSION_SAME_SITE is now LOAD-BEARING. The OAuth callback is a cross-site top-level GET back from Shopify; it carries the session cookie only while same_site is `lax` (config/session.php:202 default) or `none`. Set it to `strict` and EVERY Shopify install 403s (invalid_state) — a fail-CLOSED break, not a leak, but a total outage of the install flow. It is not set in .env.example (so it defaults to lax today). railway-infra: pin it in the env contract / predeploy guard so nobody "hardens" it into an outage.

Suggestions #3-#10 remain OPEN and do NOT gate (severity discipline: none of them hides or enables a safety failure today). Triage returned to the orchestrator: #5 (reflective allow-list audit) before Phase 3 adds models; #3 (pending-install purge) with Phase 3; #8 (webhook throttle keyed by shop_domain) before the App Store listing; #6/#4/#9/#10 -> Phase 7 backlog; #7 downgraded to NIT (the nonce now lands in the caller's OWN session, so there is no shared-cache fill).

GATE: PASS-WITH-SUGGESTIONS — Shopify Phase 2 may advance. Both release-blocking findings from the 2026-07-12 BLOCKED entry are fixed and independently re-verified.
Recurring -> archivist: class (b) "a security guard documented in a comment + constants but never implemented" is now CLOSED for Kling (the constants are live and tested) — keep the class in the registry: the detection rule is "grep for a declared const that no code path reads". Class (c) "a signed, single-use state nonce that is not bound to the initiating browser session" is recorded with its fix (park the nonce in the session; add the caller-must-be-the-named-account wall BEFORE the token exchange).
