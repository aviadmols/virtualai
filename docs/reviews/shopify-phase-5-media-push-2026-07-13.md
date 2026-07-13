# Phase 5 — Push approved images to Shopify product media (placement + snapshot + undo)

## 2026-07-13 — Phase 5 (Shopify media push / undo) — VERDICT: BLOCKED

Reviewer: code-review-gatekeeper

Scope: app/Domain/Shopify/Media/* (12 new files), app/Models/ShopifyMediaSnapshot.php,
app/Models/{ProductAsset,ActivityEvent}.php, app/Domain/Media/MediaStorage.php,
app/Domain/ProductImages/{ReviewTile,SubmitProductImageJob}.php, config/shopify.php,
app/Filament/Merchant/Pages/ProductImageStudio.php + blade + product-studio.css,
lang/{en,he}/product_images.php, migrations 2026_07_13_1500*, and
tests/Feature/Shopify/{ShopifyMediaTestSupport,ShopifyMediaPushTest,ShopifyMediaUndoTest,ShopifyMediaIsolationTest}.php

Tests run: full suite ONCE -> OK (1136 tests, 4202 assertions); the claimed growth from the 1103
baseline is confirmed. Re-run after every mutation was reverted -> OK (1136) again, so the tree is
left byte-clean.

Sweeps: withoutGlobalScopes (clean) - raw DB:: on tenant tables (clean) - hardcoded model id
(clean) - Blade::render (clean) - inline style / arbitrary Tailwind (clean) - physical-direction
CSS (0 hits) - CONST-at-top (13/13 new files) - EN/HE mirror (product_images 98/98,
activity 40/40) - credit / ledger / reservation references on the store rail (NONE).

### MUTATION TABLE (the guard is deleted — does a test go red?)

| # | Guard | Mutation applied | Result |
|---|---|---|---|
| M1 | Snapshot gate before a destructive push | isDestructive() forced false | RED (5F + 1E) |
| M2 | READY gate before placement | awaitReady() in push() removed | RED (2F) |
| M3 | The replaced media is deleted AFTER the reorder | deleteMedia / reorder swapped in place() | RED (1F) |
| M4 | shopify_media_id persisted the instant Shopify answers | the forceFill save removed | RED (1F) |
| M5 | Park index inside uniqueId() | parks dropped from the key | RED (1F) |
| M6 | assertRestorable() refuses a replace we cannot undo | body short-circuited | GREEN — UNGUARDED |
| M7 | Undo re-uploads the missing originals | reuploadOriginal() returns null | RED (2F) |
| M8 | Undo restores the original order / featured image | reorder() in restore() removed | RED (2F) |
| M9 | Snapshot capture fails closed | the MediaSnapshotException throw removed | RED (1F) |
| M10 | pushTransitionTo() requires an APPROVED asset | the approval guard removed | RED (1F) |
| M11 | BelongsToAccount on ShopifyMediaSnapshot | trait removed | RED (2F) |
| M12 | ShopifyMediaSnapshot::transitionTo() guard | illegal transitions accepted | GREEN — UNGUARDED |

Ten of the twelve guards are real. TWO are green when deleted (M6, M12) — the sixth and seventh
claimed-guard-with-no-red-test in this project.

### PROBES (adversarial tests written by the reviewer, run, then removed)

| Probe | Question | Outcome |
|---|---|---|
| P1 | Does a failure mid-restore duplicate the originals on retry? | CONFIRMED — the live gallery ends with 3 media where 2 are expected |
| P2 | Is undo idempotent when an append preceded the snapshot? | CONFIRMED BROKEN — undo 2 re-injects our AI image into the store |
| P3 | Is the snapshot byte write VERIFIED before the first delete? | CONFIRMED NOT — a failed write still returns a path |
| P3b | Blast radius of P3 | CONFIRMED — the original is gone from Shopify AND from us |
| P4 | Can a legal review move brick the undo rail? | CONFIRMED — reject-after-push leaves push_status = pushed forever |

### BLOCKING

**B1 — restore() mints Shopify media before it persists their ids (the M3b family, in undo).**
app/Domain/Shopify/Media/ShopifyMediaPusher.php:149-165 — the snapshot save at :163-165 runs only
AFTER the whole loop. Any throw inside the loop (the awaitReady at :266 exhausting its poll budget,
a throttle park, a worker crash) discards every restored_media_id gathered so far. The retry finds
the original id dead, re-uploads the SAME original again, and the merchant is left with DUPLICATE
originals in a live gallery — one more on every retry. Proven by probe P1. The push rail closed
exactly this window at :206; the restore rail did not. FIX: persist each restored id in the same
breath as the createMedia call that mints it, never after the loop.

**B2 — the snapshot captures our OWN pushed media as an original, and a second undo re-injects it.**
app/Domain/Shopify/Media/ShopifyMediaSnapshotter.php:120-155 — capture() snapshots the whole live
gallery. An append is non-destructive and takes no snapshot, so the FIRST destructive push captures
a gallery that already contains images we pushed. Undo 1 correctly deletes them; undo 2 then sees
those entries as missing originals and RE-UPLOADS our AI image into the live storefront from the
snapshot bytes, where it stays forever (the asset row is not_pushed, so it is never cleaned up
again). Proven by probe P2. This contradicts the IDEMPOTENT claim at UndoProductMediaJob:30.
FIX: exclude the media ids carried by this product product_assets.shopify_media_id from the
capture, or tag the entry as ours and never re-upload it.

**B3 — the snapshot is ATTEMPTED, never VERIFIED, and a failed byte write is silent.**
app/Domain/Media/MediaStorage.php:161-176 ignores the boolean returned by the disk put(), and every
disk in config/filesystems.php (:45, :54, :67, :84) sets throw => false — so a failed S3 / volume
write returns FALSE and storeShopifySnapshot() still hands back a StoredMedia whose path holds
nothing. ensure() then stamps the snapshot CAPTURED, and assertRestorable()
(ShopifyMediaPusher.php:283-287) only checks that the path STRING is non-empty; it never checks that
the bytes are readable. The destructive push proceeds, the original is deleted, and undo throws
notRestorable: the original is gone from Shopify AND from us. Proven by probes P3 and P3b. This is
the one question the phase must be able to answer NO to. FIX: throw when put() returns false, and
read the objects back (exists + byte size) before the snapshot may transition to CAPTURED.

**B4 — assertRestorable() is a claimed guard with no red test.**
app/Domain/Shopify/Media/ShopifyMediaPusher.php:276-293. Mutation M6: neutering it leaves all 28
Phase-5 tests GREEN. It is the ONLY wall stopping a REPLACE from deleting a media whose bytes we do
not hold — a Shopify video, a 3D model, or any entry the snapshot recorded with a null path. That
delete is irreversible on a live storefront. FIX: a test proving a replace that targets a
non-image / un-snapshotted media is refused and deletes nothing.

**B5 — a gallery larger than the page size is silently truncated and still stamped CAPTURED.**
app/Domain/Shopify/Media/ShopifyMediaQueries.php:76-79 (media(first) returning nodes only — no
pageInfo, no cursor) + ShopifyMediaClient.php:154-171. SHOPIFY_MEDIA_PER_PRODUCT defaults to 50
while Shopify allows up to 250 media on a product. A 60-image product is snapshotted as 50
originals and marked CAPTURED; a destructive position-N push then reorders, and undo can only
restore the order of the 50 it knows — the original order of the remainder is lost irrecoverably.
A snapshot must be complete or refuse. FIX: request pageInfo and either paginate or REFUSE a
destructive push on a truncated gallery (fail closed).

**B6 — rejecting an image that is LIVE in the store bricks the undo rail.**
app/Models/ProductAsset.php:331-339 + app/Domain/Shopify/Media/UndoProductMediaJob.php:113-124.
approved -> rejected is a legal review move (REVIEW_TRANSITIONS) with no push-state guard, and
ProductImageReview::reject() never looks at push_status. Undo then mutates the store (originals
restored, our media deleted) and THROWS on pushTransitionTo(PUSH_NOT_PUSHED), because
pushTransitionTo demands an approved asset. The storefront ends up correct, but the asset is
permanently stuck at push_status = pushed with a dead shopify_media_id, restore_count is never
stamped, no KIND_SHOPIFY_MEDIA_RESTORED event is written, and every later Undo click throws again —
the panel lies about the live store. Proven by probe P4. FIX: forbid rejecting a pushed asset, or
let the SYSTEM actor move a rejected asset back to not_pushed on undo.

### SUGGESTIONS

**S1** app/Models/ShopifyMediaSnapshot.php:134-163 — transitionTo() has no red test (M12 green). Pin
it the way test_an_illegal_push_transition_is_rejected pins the push machine.

**S2** No reaper for a stuck pushing asset. A SIGKILL / OOM worker never calls failed(), so the
asset stays pushing; PushProductMedia::push() then denies IN_FLIGHT (:51) and rePush() denies as
well (:84). The merchant can never push that image again. Phase 3 already ships the pattern
(SHOPIFY_RECEIPT_STUCK_MINUTES).

**S3** ShopifyMediaPusher.php:104-109 — a media whose Shopify processing ends FAILED is a dead end:
the id is persisted, so every re-push resumes it and awaitReady() throws processingFailed forever.
Clear shopify_media_id on a terminal FAILED media so a re-push can mint a fresh one.

**S4** ShopifyMediaSnapshotter.php:82-88 — the catch (Throwable) swallows a throttle
(ShopifyApiException) raised by the gallery read inside the capture and converts it into a
MediaSnapshotException, so the push FAILS instead of PARKING. Fail-closed, so not a blocker, but a
rate-limited store turns every destructive push into a manual retry. Re-throw the throttle.

**S5** ShopifyMediaSnapshotter.php:135-149 — a capture that fails on original 4 leaves 1 to 3 as
orphaned objects on the media disk with no row pointing at them.

**S6** productCreateMedia deprecation: ACCEPTABLE for App Store submission, NOT a Phase-5 blocker.
It is supported on the pinned 2026-04 (config/shopify.php:13) and Shopify does not reject an app for
calling a supported mutation. It is scheduled debt: the migration to the productUpdate / productSet
media input must land BEFORE the pinned version ages out of the supported window (about 12 months),
or the next quarterly bump breaks the push rail. Record it in docs/shopify/DECISIONS.md with the
sunset date and route it to Phase 7.

### GREEN (verified by mutation, not by assertion)

Tenant safety: ShopifyMediaSnapshot carries account_id + BelongsToAccount (M11 red), is absent from
GlobalModels::ALLOW_LIST, is unique on (account_id, product_id), and every read fails closed; both
jobs extend TenantAwareJob with an explicit int accountId; the back-to-back two-account worker test
passes. Money safety: the store rail contains ZERO credit / ledger / reservation references — push,
re-push and undo are provably free and can never re-run a generation. Order of operations: the
replaced media is deleted only after the replacement is READY (M2, M3 red), and undo removes our
media only after every original is back and READY (M7, M8 red). Idempotency: exactly one media per
asset (M4 red); the park index rides inside uniqueId() on BOTH jobs (M5 red; the undo job is pinned
by its own test). Conventions: strtr() and never Blade::render() for the alt template, CONST-at-top
on all 13 new files, zero inline CSS and zero arbitrary Tailwind, zero physical-direction CSS,
EN and HE mirrored 1:1, Blade output escaped.

GATE: **BLOCKED** — 6 blocking findings (B3 the snapshot is not verified before the first delete; B1
duplicate originals after a failed restore; B2 undo is not idempotent; B4 an untested
irreversible-delete guard; B5 a silently truncated snapshot; B6 a bricked undo after a legal reject)
plus 6 suggestions. Owner: the Shopify media author / laravel-backend (B1, B2, B3, B5, B6 and S1 to
S5). Re-review required before Phase 5 advances. Money safety and tenant safety are GREEN and are
NOT the reason for the block — the destructive rail is.

Recurring -> troubleshooting-archivist:
(a) claimed-guard-with-no-red-test has now shipped SEVEN times (M6 and M12 here); the class is: the
guard is written, and the test asserts the happy path around it rather than the guard itself.
(b) the window where the provider minted the resource but our DB write did not land (M3b)
REAPPEARED in restore() after being closed in createMedia(); the class is: persist the remote id in
the same breath as the call that mints it, never after the loop.
(c) NEW class worth registering platform-wide: a write that is attempted but never verified — every
MediaStorage method ignores the boolean returned by the disk put(), and every disk is configured
with throw => false.

---

## 2026-07-13 (RE-REVIEW #1) — Phase 5 fixes (B1-B6 + M12 + S1-S6) — VERDICT: BLOCKED

Reviewer: code-review-gatekeeper. Clears: the BLOCKED entry above — all 6 blockers + M12 are
VERIFIED FIXED by mutation. Opens: 3 NEW blockers (B7, B8, B9), all found by probe, none of them a
regression of B1-B6.

Scope: app/Domain/Media/{MediaStorage,MediaWriteException,StoredMedia}.php,
app/Domain/Shopify/Media/{ShopifyMediaPusher,ShopifyMediaSnapshotter,ShopifyMediaClient,
ShopifyMediaQueries,PushProductMedia,PushProductMediaJob,UndoProductMediaJob}.php,
app/Models/{ProductAsset,ShopifyMediaSnapshot}.php, app/Domain/ProductImages/ProductImageReview.php,
app/Filament/Merchant/Pages/ProductImageStudio.php, config/shopify.php,
tests/Feature/Shopify/{ShopifyMediaSafetyTest,ShopifyMediaTestSupport}.php.

Tests run: full suite ONCE -> OK (1157 tests, 4296 assertions); the claimed growth from the 1136
baseline is confirmed. Re-run after every mutation was reverted -> OK (1157) again: the tree is left
byte-clean.

Sweeps: withoutGlobalScopes on the changed surface (clean) - raw DB:: on tenant tables (clean) -
hardcoded model id (clean) - Blade::render (clean) - inline style / arbitrary Tailwind (clean) -
CONST-at-top (13/13) - EN/HE mirror (product_images 99/99, activity 40/40, platform 650/650) -
credit / ledger / reservation references on the store rail (NONE) - direct disk writes bypassing
MediaStorage::write() (NONE on the media rail).

### MUTATION TABLE (the guard is deleted — does a test go red?)

| #    | Guard | Result |
|------|-------|--------|
| M6a  | assertSnapshotRestorable() (the whole-snapshot byte wall) | RED (1F) — WAS GREEN |
| M6b  | assertMediaRestorable() (the one image a REPLACE deletes) | RED (1F) — WAS GREEN |
| M12  | ShopifyMediaSnapshot::transitionTo() guard | RED (1F) — WAS GREEN |
| M11  | BelongsToAccount on ShopifyMediaSnapshot | RED (16F + 6E) |
| M13  | write(): the put() boolean check | RED (1F) |
| M14  | write(): the readback | RED (1F) |
| M15  | ourMediaIds() exclusion (our image never enters a snapshot) | RED (1F) |
| M21  | assertVerified() before `captured` | RED (1F) |
| M16  | the pagination walk / galleryUnread fail-closed | RED (3F) |
| M17  | the restored media id persisted BEFORE the READY poll | RED (1F) |
| M18  | reject-while-in-store refused | RED (1F) |
| M19  | the approval gate guards only the way INTO the store | RED (1E) |
| M20  | isPushStuck() reclaim | RED (1F) |

13/13 guards are real. M6 and M12 — the two claimed-guards-with-no-red-test of the first review — are
now genuinely pinned.

### PROBES (written by the reviewer, run, then removed)

| Probe | Question | Outcome |
|-------|----------|---------|
| P5  | Can S2's reclaim race the ORIGINAL job and mint two media for one asset? | CONFIRMED — createdMediaCount() = 2 |
| P5b | Can undo remove the orphan that race left? | CONFIRMED BROKEN — our AI image is still live after Undo |
| P7  | Does the readback prove the BYTES WE INTENDED? | CONFIRMED NOT — 5000 bytes written, 1 byte landed, StoredMedia::byteSize = 1, snapshot CAPTURED |
| P9  | Can the bytes be lost BETWEEN the gate and the delete? | CONFIRMED — ops = create,reorder,delete; the original is gone from Shopify AND from us |
| P10 | Can a RESTORED original ever be replaced again? | CONFIRMED BROKEN — refused forever (fail-closed) |

### BLOCKING

**B7 — S2's reclaim mints TWO Shopify media for ONE asset, and Undo can never remove the second.**
PushProductMediaJob.php:157-184 (lockAndClaim) + ProductAsset.php:336-343 (isPushStuck) +
PushProductMedia.php:55. The `pushing` branch admits an asset when `parks > 0 || isPushStuck()` and
returns it WITHOUT re-stamping updated_at and without any claim identity — so a merchant's reclaim
(parks=0) and the original job's parked continuation (parks=1, merely slow in a backlog) are BOTH
admitted inside the same stuck window. Layer 3 (resume from shopify_media_id, Pusher:116-118) only
closes the duplicate window when the id was ALREADY persisted — and the killed-worker case the
reclaim exists for is exactly the one where it is NOT. Probe P5: two `create` calls, two media in the
live gallery. Probe P5b: the asset row keeps only the LAST id, so UndoProductMediaJob::pushedAssets()
(:157-166) deletes only that one — the other AI image stays in the merchant's live storefront after
"restore my original images", forever. This is the B1 class (a duplicate in a live gallery after a
retry), and it makes PushProductMediaJob:27-33 ("EXACTLY ONE SHOPIFY MEDIA PER ASSET ... three
layers") false as written.
FIX: make the claim a LEASE — inside the row-locked transaction, touch() the asset whenever a
`pushing` row is admitted AND stamp a claim id the job must carry, so a second worker finds a fresh
claim and short-circuits. TEST: a stuck asset with NO shopify_media_id + a second worker admitted in
the same window -> exactly one `create`, and Undo removes every media we minted.

**B8 — the "verified write" verifies the wrong predicate: a TRUNCATED object passes, and it licenses
the deletion of a live original.** MediaStorage.php:378-391 (with :112 MIN_VERIFIED_BYTES and :386).
write() accepts any object of >= 1 byte; it never compares the readback size to strlen($bytes). Probe
P7: storeShopifySnapshot() is handed 5000 bytes, the disk stores 1, and it returns
StoredMedia(byteSize: 1) — the snapshot entry records bytes: 1, and assertVerified()
(Snapshotter:216-232) and assertSnapshotRestorable()/assertMediaRestorable() (Pusher:329-371) all
pass, because every one of them asks isReadable() (= size >= 1). The destructive push then deletes
the merchant's live original and Undo hands back a corrupt image.
REACHABLE IN PRODUCTION: MEDIA_DISK=volume (a Railway Volume, local driver) is a supported mode;
Flysystem's local adapter writes with file_put_contents, which on a FULL disk performs a SHORT write
and returns a byte count — not false — so put() says yes and the object is truncated. "The volume is
full" therefore equals "the original is gone from Shopify and what we hold is not the image".
FIX (one line): if ($stored !== strlen($bytes)) -> MediaWriteException::unverified().
TEST: a short write throws, and the destructive push is refused.

**B9 — TOCTOU: the bytes are proved at the START of the push; the original is deleted a minute later.**
ShopifyMediaPusher.php:106 and :111 (the gates) vs :253 (the delete). Between them run a staged
upload, a productCreateMedia and a READY poll (up to 20 x 3s). Nothing re-checks the bytes. Probe P9
makes the snapshot objects vanish during the staged upload — the exact threat the phase's OWN test
(test_a_snapshot_whose_objects_vanish_mid_capture...: "a bucket lifecycle rule, a bad purge, a racing
cleanup") treats as real — and the delete still runs: ops = create,reorder,delete, the original gone
from Shopify AND from us.
FIX: re-assert the target's bytes immediately before $this->client->deleteMedia() in place() — the
last statement before the only irreversible call in the system. TEST: drop the snapshot object after
the READY gate; assert nothing is deleted.

### SUGGESTIONS

**S7** ShopifyMediaPusher.php:354-371 — after ONE undo, a restored original can NEVER be replaced
again: assertMediaRestorable() matches only ENTRY_MEDIA_ID, while a restored original lives under
ENTRY_RESTORED_MEDIA_ID, so the push is refused with "was never backed up" (which is a lie).
Fail-CLOSED, so not a blocker — but the destructive rail is dead for every product that was ever
undone (probe P10). Match either key.

**S8** ShopifyMediaSnapshotter.php:240-248 — ourMediaIds() (the B2 fix) is built from a MUTABLE column
that two paths deliberately NULL (UndoProductMediaJob:114-121, and the S3 dead-media clear at
Pusher:314) and that B7's race overwrites. Any media still live in Shopify whose link was dropped is
invisible to the exclusion and can be captured as a merchant "original" by a later snapshot — the B2
scar through a side door. Record our minted media somewhere append-only that is never nulled.

**S9** ShopifyMediaPusher.php:198 + UndoProductMediaJob:113-124 — restore() returns Shopify's
deletedMediaIds, but nobody checks that every id we asked to delete is IN it; the asset link is
cleared regardless. A delete Shopify reports but does not perform leaves our image live AND unlinked
(see S8).

**S10** UndoProductMediaJob:157-166 — pushedAssets() only sees `pushed` assets, but a push that fails
AFTER createMedia (e.g. the reorder throws) leaves our media LIVE with the asset at `push_failed`.
Undo will not remove it; only a successful re-push followed by an undo can.

**S11** StartGeneration.php:103 — a MediaWriteException on the source-photo write now escapes as an
untyped 500 (GenerationController catches only GenerationStartException). No money impact (the
transaction rolls back and nothing is reserved), but the shopper sees a 500. Map it to a typed error.

**S12** ShopifyMediaClient.php:244-253 — find() now walks the WHOLE paginated gallery, and awaitReady()
calls it up to 20 times: up to 200 cost-weighted GraphQL calls per media on a large gallery, on the
one rail that must not throttle. Use a targeted read, or exit the walk early on a hit.

### GREEN (verified by mutation/probe, not by assertion)

B1 VERIFIED FIXED (M17 red: a crash mid-restore resumes — 2 media, not 3). B2 VERIFIED FIXED (M15
red: a second undo re-injects nothing). B3 VERIFIED FIXED at the gateway (M13 and M14 red
independently — the put() boolean and the readback are each pinned), but see B8: the readback checks
the wrong predicate. B4 VERIFIED FIXED (M6a + M6b red — the ungarded guard of the first review is now
two walls, both pinned). B5 VERIFIED FIXED (M16 red: a truncated gallery fails closed). B6 VERIFIED
FIXED (M18 + M19 red, both directions: a live image cannot be rejected, and undo needs no approval to
close the loop). M12 VERIFIED FIXED. S3/S4/S5 confirmed by reading + M21.

MONEY SAFETY — STILL GREEN. The store rail holds ZERO credit/ledger/reservation references (sweep).
The new throwing write gateway does NOT regress store-before-charge: ProductImageMoneyPathTest pins
BOTH a throwing disk (breakTheMediaDisk) and a silently-refusing disk (breakTheMediaDiskSilently) ->
STORAGE_FAILED, hold released, ZERO charge rows, balance intact; and deleting either half of the
gateway's guard (M13 / M14) goes red. A push / re-push / undo still cannot touch the ledger.

TENANT SAFETY — STILL GREEN. M11 (BelongsToAccount off ShopifyMediaSnapshot) -> 16F + 6E. ourMediaIds()
runs under the global scope; both jobs are TenantAwareJob with an explicit int accountId; every media
path leads with account_id. The "another account pushed to the same Shopify product" attack is
structurally impossible: shopify_connections.shop_domain is globally unique and site_id is unique
(migration 2026_07_12_200001:24,27) — one shop maps to exactly one site of exactly one account.

### THE GATE QUESTION

"Can ANY sequence of failures, retries, races or crashes leave a merchant's live product with a
deleted original image we cannot restore?"

ANSWER: **YES — still.** Two ways, both proven with a probe:

  1. B8 — a SHORT write on a full local/volume disk: put() returns a byte count (not false), the
     object exists, the readback only asks "is it >= 1 byte?", the snapshot is stamped CAPTURED, the
     live original is deleted, and the bytes we hand back are not the image (probe P7).
  2. B9 — the snapshot object is lost AFTER the pre-flight gates and BEFORE the delete; the delete
     still runs (probe P9: the original is gone from Shopify AND from us).

B7 does not lose an original, but it permanently plants an AI image in a live storefront that no Undo
can remove (probe P5b).

GATE: **BLOCKED** — 3 blocking findings (B7 the reclaim race duplicates media and bricks undo; B8 the
verified write is not verified against the bytes we wrote; B9 the byte proof is stale by the time the
delete runs) plus 6 suggestions. Owner: laravel-backend (all three). The six original blockers ARE
fixed and are NOT the reason for this block. Re-review required before Phase 5 advances.

Recurring -> troubleshooting-archivist:
(a) "a write attempted is not a write verified" has produced its SECOND generation: the check now
    exists but verifies the WRONG predicate (>= 1 byte, instead of == the bytes we handed the disk).
    The class is: a verification that cannot distinguish "our bytes" from "some bytes".
(b) NEW class — a TIME-OF-CHECK / TIME-OF-USE gap on an irreversible action: the proof is taken at the
    top of the method and the destruction happens 60 seconds later. Re-prove immediately before the
    irreversible call.
(c) NEW class — a reclaim / stuck-lease that admits a SECOND worker because the claim never re-stamps
    the freshness field it is judged by (isPushStuck reads updated_at; the claim never touches it).
