## 2026-06-25T00:00:00Z — Phase 4 (PDP Scan, pdp-scanner lead) — VERDICT: BLOCKED
Reviewer: code-review-gatekeeper
Scope: app/Domain/Scan/** (Fetch/, Represent/, Selectors/, Map/, Contract/, ScanConstants.php,
  PdpScanner.php, ScanProductJob.php), app/Models/Product.php, app/Models/ProductVariant.php,
  database/migrations/2026_06_25_100000_*, _100001_*, factories, app/Providers/ScanServiceProvider.php,
  config/services.php (SCRAPER_*), docs/scan-contract.md
Sweeps run: withoutGlobalScope (clean — only the doc-comment in BelongsToAccount.php) ·
  raw DB:: on scan tables (clean) · hardcoded model id / quality / aspect-ratio in scan services (clean) ·
  Blade::render (clean) · random idempotency key (clean)
Tests run: php artisan test --filter "Scan|Tenancy" -> 88 passed (273 assertions) ;
  full suite -> 123 passed (346 assertions). Suite is green WITH the SSRF gap present
  (i.e. the green suite does NOT exercise the gap — see #1/#2/#3).

### SSRF VERDICT (headline) — per vector
- Non-http(s) schemes (file:/gopher:/ftp:/data:) ............ PASS (UrlGuard ALLOWED_SCHEMES)
- Literal private/loopback/reserved IPv4 (10/8,172.16/12,192.168/16,127/8,0/8,169.254/16) PASS
- Cloud metadata literal 169.254.169.254 .................... PASS (private+reserved filter)
- Literal IPv6 ::1 / fc00::/7 / fe80::/10 .................. PASS (parse_url + filter_var)
- Decimal-int host (2130706433) ............................ PASS (no dot -> refused)
- DNS rebinding / hostname that RESOLVES to a private/metadata IP .. FAIL (BLOCKING #1)
- Redirect target re-validation (302 -> internal IP) ....... FAIL (BLOCKING #2)
- MAX_BYTES enforced MID-STREAM (not after full download) .. FAIL (BLOCKING #3)
- Octal/obfuscated-IP host (0177.0.0.1 -> 127.0.0.1) ....... FAIL (BLOCKING #1, same root cause)

Blocking:
  #1 No DNS-resolution egress control — UrlGuard validates the input HOST STRING only;
     a hostname (metadata.google.internal, or a rebinding host, or octal 0177.0.0.1)
     that resolves to a private/metadata IP is fetched. app/Domain/Scan/Fetch/UrlGuard.php:44-53,
     consumed at HttpPageFetcher.php:42 / PageFetcherManager.php:29 / HeadlessPageFetcher.php:27 /
     RobotsPolicy.php:63.
  #2 Redirects not re-validated — HttpPageFetcher.php:76 enables allow_redirects(max 5);
     UrlGuard runs on the INPUT url only, never on redirect hops or the resolved IP of the
     final host. A public page 302-ing to http://169.254.169.254/ is followed.
  #3 MAX_BYTES applied after a full buffered download — HttpPageFetcher.php:104-115 calls
     $response->body() (whole body in memory) THEN substr(). The cap is post-hoc, not a
     mid-stream/streamed cap; an oversize/slow-loris/decompression-bomb response OOMs first.
  #4 SSRF egress guards are UNTESTED for the failing vectors — FetchStrategyTest.php:29-37
     only covers literal IPs + schemes; no test for hostname->private resolution, redirects,
     or the byte cap. A guard with no red-when-removed test is unguarded (the green suite is
     blind to #1/#2/#3).

Suggestions:
  #5 RobotsPolicy.php:63 + HeadlessPageFetcher.php:73 perform their own outbound HTTP and must
     ride the SAME resolved-IP guard once #1 lands (robots.txt + sidecar URL are also egress).
  #6 ScanProductJob.persistFailure (ScanProductJob.php:138-144) sets status directly on the
     new-row branch; only the existing-row branch routes through markFailed()/transitionTo().
     Acceptable (a fresh row has no prior state) but a single guarded ent^path reads cleaner.

### Tenancy reconciliation (TS-TENANCY-004) — CONFIRMED CORRECT, no leak
Verified by reading BelongsToAccount.php:39-60 + both vectors:
  - Mass-assign foreign account_id: account_id is NOT in Product/ProductVariant $fillable, so it
    is dropped; the creating hook sees $explicit === null and stamps Tenant::id() (the BOUND
    account). Row lands under the bound tenant; foreign account gets 0 rows. No leak.
    (ProductScanIsolationSpotCheckTest.php:152-172 asserts exactly this.)
  - Direct attribute-set foreign account_id: bypasses $fillable; the hook sees $explicit !== null,
    Tenant::check() true and $explicit !== Tenant::id() -> throws CrossTenantWriteException; nothing
    persists. (Test :174-202 + :204-230 assert this for both models.)
  - The READ-side guarantee is independent of the write vector: AccountScope (BelongsToAccount.php:84-94)
    fails closed to sentinel 0 when unbound and filters account_id when bound, so neither vector can
    produce a cross-account READ. The reconciliation is sound; the per-vector split is the real behaviour.

### Other contract checks — PASS
  - Product/ProductVariant: account_id NOT NULL + FK + composite index leading account_id
    (migrations :24-29 / :86-87 and :24-25 / :51); both use BelongsToAccount; neither on
    GlobalModels::ALLOW_LIST (asserted ProductScanIsolationSpotCheckTest.php:234-247). PASS
  - ScanProductJob extends TenantAwareJob (explicit ctor account_id, handle() final ->
    Tenant::run -> clears in finally), implements ShouldBeUnique keyed
    scan:{account_id}:{site_id}:{sha1(url)} (ScanProductJob.php:48-57). PASS
  - AI configurability: PdpScanner resolves the bag via AiOperationResolver::for(KEY_PRODUCT_SCAN)
    (PdpScanner.php:65); no model id / prompt / quality / aspect-ratio literal in any scan service.
    The OpenRouter key is never read in app/Domain/Scan. PASS
  - Never auto-approve: a fresh scan persists STATUS_DRAFT (ScanProductJob.php:96); confirmed is
    reached only via Product::confirm() -> guarded transitionTo() (Product.php:130-172). PASS
  - CONST-at-top, English-only comments, small SRP classes, no inline CSS, strtr-not-Blade. PASS

Re-review: REQUIRED (pdp-scanner) on #1-#4 before the Phase-4 gate may flip.
Recurring -> archivist: SSRF "validate input host, never the resolved IP / redirect target" is a
  classic egress-filter gap — hand the CLASS (resolve-then-pin, re-validate after redirects,
  stream-cap) to troubleshooting-archivist so the generation/media-fetch paths inherit the fix.
Cross-ref: flag #1/#2 to saas-credits-billing's tenant-isolation audit — server-side fetch of
  attacker URLs is an isolation/infra surface, not just a scan concern.

---

## 2026-06-25T00:00:00Z — Phase 4 RE-REVIEW (SSRF remediation #1–#5) — VERDICT: GREEN
Reviewer: code-review-gatekeeper
Clears: the BLOCKED entry above (4 SSRF blockers + #5 suggestion). This is a new
  dated entry; the prior verdict is left intact (append-only).
Scope (new/changed): app/Domain/Scan/Fetch/{UrlGuard,IpNormaliser,HostResolver,
  SystemHostResolver,GuardedHttpClient,SingleHopTransport,GuzzleSingleHopTransport,
  BoundedSink,TransportResponse,GuardedResponse,HttpPageFetcher,RobotsPolicy,
  HeadlessPageFetcher}.php, app/Providers/ScanServiceProvider.php,
  app/Domain/Scan/ScanConstants.php (EGRESS_*), tests/Feature/Scan/FetchEgressGuardTest.php
Sweeps run: unguarded egress in app/Domain/Scan (Http::/file_get_contents/curl_/
  gethostby/dns_get_record outside the transport+resolver seams) -> NONE: the only
  ->get( calls are $this->client->get on GuardedHttpClient (HttpPageFetcher:46,
  RobotsPolicy:68). EGRESS_* consts present (ScanConstants:111-121). withoutGlobalScope/
  raw DB/Blade::render/random-idempotency -> clean.
Tests run: php artisan test -> 136 passed (395 assertions); was 123/346 (+13 tests,
  +49 assertions, all egress). FetchEgressGuard+Scan+Tenancy filtered -> 101 passed.

### Per-finding verdict
- #1 resolve-to-private + pin .... PASS. UrlGuard::resolveAndValidate (UrlGuard.php:74-100)
    resolves via the injected HostResolver and throws if ANY resolved IP is non-public
    (:92-97). IpNormaliser unmasks octal/hex/dword/short v4 (parseObfuscatedV4 :154-200)
    and unwraps IPv4-mapped IPv6 (mappedV4 :137-151); BLOCKED_V4_CIDRS includes
    169.254.0.0/16 + 0/8 + CGNAT + reserved (:23-38), BLOCKED_V6_CIDRS covers ::1/fc00::/7/
    fe80::/10/mapped (:41-51). metadata.google.internal + "metadata" added to BLOCKED_HOSTS
    (:34-35). TOCTOU/rebinding closed by CURLOPT_RESOLVE host:port:ip pin with Host/SNI
    preserved (GuzzleSingleHopTransport:75-82) + CURLOPT_PROTOCOLS/REDIR_PROTOCOLS http(s)-only.
- #2 redirect re-validation ...... PASS. allow_redirects=false + CURLOPT_FOLLOWLOCATION=false
    (GuzzleSingleHopTransport:47,83); GuardedHttpClient::run re-runs resolveAndValidate+re-pin
    on EVERY hop in the for-loop (GuardedHttpClient.php:77-107). A 302->169.254 is refused and
    the internal hop is never sent (proven by sentUrls count, test:145-146).
- #3 mid-stream byte cap ......... PASS. BoundedSink::write returns 0 (!= strlen) to abort the
    curl transfer the instant the ceiling is crossed (BoundedSink.php:32-52), wired as
    CURLOPT_WRITEFUNCTION (GuzzleSingleHopTransport:86-88). Never a post-download substr on the
    real path; the Http::fake backstop (:112-121) is a test-only fallback and the mid-stream
    guarantee is proven directly against BoundedSink, not through it.
- #4 tests are real, NOT theatre .. PASS — verified by MUTATION TESTING (gatekeeper ran these,
    reverted after; no product code changed):
      * neutered the `! IpNormaliser::isPublic($ip)` throw in resolveAndValidate -> 2 egress
        tests went RED (loopback-resolve + metadata-resolve).
      * made BoundedSink::write accept the full chunk -> 2 egress tests went RED (both
        mid-stream-cap tests).
      * guarded only hop 0 in GuardedHttpClient::run -> 1 egress test went RED
        (redirect-to-internal). Full suite green again after revert (136/395).
- #5 shared guarded egress ........ PASS. HttpPageFetcher, RobotsPolicy and HeadlessPageFetcher
    all ride GuardedHttpClient (constructor-injected). The sidecar POST uses postJsonInternal/
    resolvePinned (pinned + capped + no-redirect, private-IP rejection skipped) — justified
    because SCRAPER_SERVICE_URL is OPERATOR config, never merchant input; the merchant URL is
    still assertFetchable-guarded (HeadlessPageFetcher:30) and travels in the JSON body, not as
    the fetch target. Correct trust boundary.

### Carried-forward checks (still PASS, unchanged by the remediation)
  Tenancy reconciliation TS-TENANCY-004 (no leak by either write vector); account_id NOT NULL +
  BelongsToAccount + composite index leading account_id; ScanProductJob TenantAwareJob +
  ShouldBeUnique scan:{account_id}:{site_id}:{sha1(url)}; AI bag via AiOperationResolver, no
  hardcoded model/prompt; never-auto-approve (draft -> confirm() guarded); CONST-at-top,
  strtr-not-Blade, no inline CSS. All green.

GATE: GREEN — all 4 SSRF blockers closed and proven (mutation-tested red-when-removed), #5
  resolved, no egress bypass, full suite 136/395 green, no safety regressions. The Phase-4
  PDP-scan gate may flip GREEN.
Recurring -> archivist: the egress-guard CLASS now has a reference shape (resolve-then-pin via
  CURLOPT_RESOLVE, re-validate every redirect hop, mid-stream BoundedSink cap, single
  GuardedHttpClient entry point) — hand to troubleshooting-archivist so the generation/media
  server-side fetch paths inherit it rather than re-deriving it.
