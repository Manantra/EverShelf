# Stability development: configuration and feature compatibility

**Mandatory for every change on this fork, not only shopping-list work.**
We fix reliability, not user-facing feature scope. An existing option,
intentional behavior, saved preference, manual override or third-party
integration is a **contract** unless a failing behavior test and review
justify a change. Never make the default mode overwrite a configured mode.

## Before modifying code

1. List all relevant feature switches and their precedence: `.env`,
   `app_settings`, Settings API, client local settings, migration defaults.
   Preserve explicit selections, including a distinction between
   *unset/automatic* and a chosen value.
2. Identify the complete write/read lifecycle: HTTP request, SQLite changes,
   cached/queued state, background cron, real-time updates and browser reload.
3. Map shared helpers used by other modes. A change in `shopping_sync.php`
   can affect **both internal and Bring!**; a change in `api/index.php` can
   affect desktop, mobile, kiosk, Home Assistant and API clients.
4. Add at least one failing regression for the bug and positive control cases
   proving deliberately different settings still behave differently.
5. Include manual edits and user-locked overrides as controls; do not silently
   normalize, truncate or recompute them.
6. Check all relevant unit scales, expiry and family semantics, locale-specific
   labels, partial/full purchases, false/offline results and transaction history.
7. Run the full existing suite, real HTTP/SQLite smoke tests, then fork CI.
   Report which integration modes **were not tested** (notably real third-party
   Bring! credentials, Safari/PWA and Android) instead of claiming coverage.
8. Never put household data, tokens, credentials or private service URLs in
   test fixtures or public commits. Do not deploy the experimental fork to the
   production server without backups and a separate explicit decision.

## Compatibility matrix (shopping/stock work)

| Setting or behavior | Variants to protect | Currently checked |
| --- | --- | --- |
| `shopping_plan_days` | user 7/14/30, automatic/inferred, explicit API override | PHP compatibility tests; legacy cache falls back to configured days |
| `SHOPPING_MODE` | internal; Bring! selected with and without credentials | mode dispatch tested with synthetic config; **no real Bring! network test** |
| `SHOPPING_REMOVE_ON_BUY` | `plan`: keep when stock is insufficient; `trip`: remove after buying | config dispatch and repeated HTTP+SQLite purchases in both modes, including cron and real-time sync; **no real Bring! account used** |
| `SHOPPING_DISMISS_DAYS`, `SHOPPING_REMOVED_BLOCK_MODE` | dated dismissal, purchase, default/override | existing dismiss regressions; one HTTP cron/live-sync path |
| Packaging | `conf`, `pz`, `g`, `ml`; `default_quantity` and `package_unit` | 7/14/30-day cached quantity guards and HTTP 690 g per pack; `kg`/`l` not covered exhaustively |
| User overrides | manually entered product casing, manually entered shopping quantity | PHP and HTTP persistence checks; **never cap user-entered 24 packs** |
| Item families | generic `shopping_name`, specific `raw_name`, variants, stock from same scale | existing restock tests; HTTP distinct generic/raw-name dismiss |
| Lifecycle | first save, cache reload, cron auto-add, live sync, partial restock | documented real HTTP/SQLite paths; concurrency and multi-client conflicts still missing |
| Other feature flags | `PRODUCT_VARIANTS_ENABLED`, `PRODUCT_KIND_PREFIX`, `TAGS_ENABLED`, AI, barcode, recipe planning, HA | existing tests cover some; **not all fully exercised** by this patch |

All future work should extend the matrix where it touches other settings
(e.g. product families, locations, weighing scales, language or integrations).
Do **not** read this table as certification that every EverShelf setting works.

## Confirmed: inflated automatic cached suggestion bypassed the existing cap

The main planner already invokes `shoppingCapSuggestedQty()` when it
calculates a shopping suggestion. But the common
`evershelfBuildShoppingSpec()` path could render a previously cached
`suggested_qty=24, suggested_unit=conf` directly as 24 packs without
reapplying the existing guard. The same helper is used by internal list
and Bring! auto-sync.

**Narrow fix:** reapply the **existing** guard only when formatting a
*smart-shopping generated* specification, based on its
`suggested_unit`, `default_qty`/`default_quantity`, `package_unit`
and `edible_days` / `plan_days`. For an old cache with neither
horizon, the two auto-sync callers supply a PDO connection so the
explicit `shopping_plan_days` setting remains authoritative. The
old 7/14-day cap gives 3 packs, a 30-day window 5 packs. No new hard
cap was introduced and the original forecast data is not mutated.

**Boundaries:** explicitly manual `shopping_add` specifications bypass
this auto-generation method and remain unchanged. Current smart
calculations, unit display, rounding and forecast configuration remain
unchanged. This guards *new* auto-synced specifications; it does not
retroactively rewrite unrelated existing rows in a household database.

## Fixed and regression-locked: plan-mode partial restock survives reload

The isolated `scripts/integration-restock-settings.py` reproducer originally
confirmed a bug: after buying 1 of 3 required packs with
`SHOPPING_REMOVE_ON_BUY=plan`, the API said `shopping_kept:true` but the
next `shopping_list` call silently deleted the row. Stock additions reset the
forecast cache, and in the absence of consumption history the next read
mistook *any* positive stock for complete coverage.

**Fix:** carry forward an existing *calculated* plan-mode target via the
schema's original `shopping_list.need_qty` and `need_unit` fields. When no
current smart forecast exists, each successful stock addition reduces that
persisted deficit through EverShelf's native unit-family conversion. The
shopping-list read, internal cron cleanup and real-time sync preserve an
outstanding positive target instead of treating missing cache as full
coverage. Once the deficit reaches zero, the normal removal/purchase-block
flow runs. We neither added database columns nor changed the global default.

**Compatibility guards:**

- This state protection is limited to `plan` mode, the internal list and
  a server-generated `🛒 Compra:`/`🛒 Almeno:` specification with
  positive `need_qty` and an explicit `need_unit`.
- A manual replacement of that text disables the tracking override;
  unrelated manually entered quantities remain untouched.
- `trip` still removes after the first purchase. With a real Bring!
  connection configured, the external transport retains its prior logic.
- Product families are matched using the existing shopping-name logic;
  only compatible mass/volume/pack units are converted. An invalid unit
  cannot be subtracted silently.
- Existing out-of-season removal happens **before** preservation of tracked
  quantities. Intentional `🛒 Esaurito` rows retain their special handling.

**Verified HTTP sequence (independent SQLite):**

| Mode | Step | Expected result | Local result |
| --- | --- | --- | --- |
| plan | 3 required, buy 1 | 2 pending; survives cron/reload/live sync | PASS |
| plan | buy another 1 | 1 pending; survives cron/reload/live sync | PASS |
| plan | buy final 1 | shopping row removed, 3 in stock | PASS |
| trip | 3 required, buy 1 | shopping row removed immediately | PASS |
| trip | buy another 1 | row remains removed, stock increases | PASS |

The extended `test-internal-shopping-cleanup.php` also covers
690 g → 1 conf equivalence and rejects an incompatible liquid/solid unit.
The two existing temporary-SQLite test fixtures now include the existing
`need_qty`/`need_unit` columns, matching the real schema.

**Limitations:** The new tracking starts from an *already computed* positive
restock deficit. A standalone user-entered note with no numeric shopping
metadata does not create an automatic multi-purchase target. No live Bring!,
real multi-client races or variant-family consolidation has been tested.

## Current limitations

Shipping Docker/Apache, genuine Bring! account integration, multiple
devices racing, real PWA/offline storage, locale coverage of UI text and
mixed mass/volume shopping family conversion are outside the validated
scope of this quantity patch. Treat them as separate test gaps, not as
permission to change the corresponding behavior casually.

## Heartbeat / offline state (2026-10-30)

The heartbeat is **not** controlled by .env and introduces no new user-facing
setting. Its previous one-failure/7-second network-ping threshold caused false
blocking overlays behind slower SSO/reverse proxies. The fork uses a
15-second request deadline and requires two consecutive ping failures to
change server state; the first failure is rechecked at the **existing 5-second
retry interval**, normal success at **20 seconds**. There is only one in-flight
ping, preventing a manual retry and periodic check from racing each other.

Contracts preserved and checked in `scripts/test-heartbeat.js`:

- A single failed HTTP response or timeout never blocks the app, while a
  sustained outage still calls `_showNetworkOverlay()` exactly once.
- A successful ping restores online mode, clears the failure counter, and
  calls `_handleServerRestored()` **once** per offline→online transition.
  That handler still triggers the existing offline write-queue replay path.
- If the user intentionally entered `_offlineMode`, the heartbeat must not
  cover the locally cached offline UI with an additional full-screen overlay.
- Background and manual checks cannot run concurrently or leave duplicated
  polling timers.
- All 17 app-shell resource version stamps and the PWA Service Worker cache
  key change together, so clients can fetch the new code on subsequent visits.

**Not yet validated:** real iPhone Safari/PWA and Pangolin SSO end-to-end,
WebView sleep/wake suspension, background-tab timer throttling, and actual
network latency distributions. These need isolated browser/device validation
before any rollout to the household instance. The native browser `offline`
event and the independent UI network overlay retain their existing behavior.

## Multi-worker inventory concurrency (2026-10-30) — CORE WRITE FIXED

**Confirmed on isolated loopback servers before the fix.** With two separate
PHP web-server processes sharing a disposable WAL-mode SQLite file, parallel
`inventory_add` requests can intermittently return HTTP 500 with
`SQLSTATE[HY000]: General error: 5 database is locked`.

A reproducible, opt-in stress test is in
`scripts/integration-concurrent-inventory.py`. Start two independent local
PHP servers on ports 18080 and 18081, both pointing to the same disposable
sandbox whose `.env` contains `EVERSHELF_SANDBOX=1`, then run:

```bash
python3 scripts/integration-concurrent-inventory.py \
  --sandbox-root /path/to/disposable/sandbox \
  --base-url http://127.0.0.1:18080 \
  --second-url http://127.0.0.1:18081 --rounds 3 --parallel 12
```

**Baseline:** 2 of 36 concurrent adds returned 500. All 34 successful
acknowledgements were reflected in stock in that run. Reordering SQLite
busy-timeout and WAL pragmas still yielded 2 errors out of 60; this trial
change was **reverted**, as it did not fix the lock failures.

**Critical additional observation:** a subsequent isolated run had 33 successful
HTTP acknowledgements yet **35 units persisted in stock**, while 2 other
requests returned HTTP 500. This is a failed-after-write case: a user/client
retry of the reported error could double-count the stock. The result demonstrates
that partial writes must be considered an actual failure mode, not only a
hypothetical concern. SQLite `quick_check` alone cannot detect such logical
inconsistency. The stress test now also checks the corresponding transaction
ledger against the number of acknowledged requests.

**Root cause confirmed:** a failed transaction-ledger INSERT occurred **after**
the inventory UPDATE had already committed. In a test-only diagnostic wrapper,
the relevant lock errors were on the inventory UPDATE and on the INSERT into
`transactions`. A 500 error after the stock UPDATE could mislead the caller
into resubmitting and double-counting the inventory.

**Fix:** the `inventory_add` action now acquires a SQLite `BEGIN IMMEDIATE` lock
(using EverShelf's existing `dbWithRetry` for *lock acquisition only*).
Product unit changes, the inventory row/quantity and its transaction ledger
entry are committed together or rolled back together. After that commit,
shopping-list reconciliation and optional Bring! I/O run separately, so a
postcommit failure does not imply that the original purchase failed. Such a
response remains `success:true` for **persisted stock** and includes
`shopping_sync_pending:true`; a rejected core transaction returns HTTP 503
`database_busy` and cannot have changed inventory or ledger.

**Verified using two independent PHP workers:**

- 60/60 concurrent stock requests succeeded with exactly 60 inventory
  increments and 60 ledger units.
- In another 60-request run, 59 succeeded and one returned a clean 503;
  **inventory and ledger both counted precisely 59**. No unacknowledged stock
  additions occurred in the reruns.
- Deliberately throwing an exception from the shopping-sync function *after*
  the stock transaction returned HTTP 200 `success:true` with
  `shopping_sync_pending:true`, while SQLite held one stock unit and one
  transaction-ledger entry.
- The existing 52 PHP regression tests and HTTP persistence, shopping dismiss,
  plan/trip partial-buy, heartbeat, and JavaScript tests all passed.

**Compatibility:** database schema unchanged; existing SQLite WAL/busy timeout,
rate limiter, expiry/location row merging, unit conversions, shopping modes,
manual edits and feature switches preserved. The stress script resets the
**disposable sandbox's** rate-limit fixtures before each run (because its
60 test requests otherwise exceed the intentional 120/minute guard over
repeated invocations). The production rate limiter is not modified.

**Limits / remaining risks:** a simultaneous duplicate-product consolidation
before the main stock transaction is not covered by this atomicity guarantee;
real Bring! integration, power-loss recovery, multiple-instance deployments,
and same-field stale edits from two browser tabs still need separate work.
External shopping sync after commit can remain pending and must be reconciled
via its normal later sync mechanism. The two-worker test is now required CI;
HTTP 503 is allowed only when the write was fully rejected and no quantity or
ledger entry was persisted.


## Concurrent manual product title edits (2026-10-30)

**Reproduced:** Two tabs opened the same product; tab A saved a renamed
title, then tab B submitted its earlier snapshot. Previously both
`product_save` requests returned HTTP 200, and the last title silently won.

**Fix:** manual name edit flows now explicitly supply `expected_name`,
the *exact* title from when their editor was opened. The server checks
it and performs the actual update with an atomic `WHERE id=? AND name=?`
condition; an intervening rename returns HTTP 409 with
`{success:false,error:"product_conflict"}`. No other product or inventory
fields are written for a rejected stale edit. The modal stays open and
shows a translated instruction to reopen/review the current product.
The inventory edit modal does not continue to `inventory_update` after
its product rename was rejected.

**Compatibility:** Opt-in only. Existing scan, import, barcode lookup,
AI enrichment and third-party clients omitting `expected_name` retain
their original merge/rename behavior, including product kind prefixes,
manual title locks and optional product variants. No new .env setting
or database migration is required. The guard rejects a barcode conflict
before attempting a duplicate-product merge when a manual editor provided
a snapshot, avoiding side effects before the atomic rename check.
All six languages have the new conflict notification.

**Tests:** The actual HTTP/SQLite persistence suite covers a stale
second editor, a deliberately changed package quantity that must not
persist on rejection, refreshing the expected title and saving again.
The two-worker concurrent HTTP test sends eight different renames against
the same original title: precisely one succeeds; the other seven return
409. The JS handler tests verify the two edit windows show a localized
error, and that a failed rename cannot trigger `inventory_update`.

**Remaining limitations:** This first guard detects title conflicts
only. Two simultaneous edits to *other* product fields that keep the
same title, or two concurrent edits to the *same inventory row's quantity*,
may still follow existing last-write-wins semantics. Protect these using
field-aware expected snapshots or an appropriate row revision mechanism,
after reviewing all scanners, apps, catalog imports and offline operations.
Clients without the new parameter retain last-write-wins by design.
This patch does not claim full multi-user conflict resolution or tested
iPhone Safari/PWA execution; the browser test covers the real handlers
with a simulated DOM and the separate HTTP tests cover the database.


## Additional field and inventory conflicts (2026-10-30)

**Previously open:** manual editors could silently overwrite another device's
changes to brand, product notes, category, package information, inventory
quantity, location, vacuum status or expiry even when the product name itself
had not changed. The product-name-only `expected_name` guard was insufficient.

**Guarded product edits (opt-in):** `product_save` now also accepts
`expected_fields`, a map of original persisted field values from when the
editor opened. The server checks an explicit whitelist of permitted columns
(`name`, `brand`, `category`, `notes`, `image_url`, `unit`,
`default_quantity`, `package_unit`, `barcode`) using SQLite's NULL-safe
`IS` comparisons directly in the SQL `UPDATE` predicate. A stale field
causes HTTP 409 `product_conflict`, with **no saved changes** to that product.
Invalid snapshot keys are rejected with HTTP 400. The existing
`expected_name` guard still works, and the regular scanner, imports,
catalog sync and legacy API requests without snapshots remain compatible.

Both manual product editing screens now send original field snapshots. The
inventory listing also now includes `p.notes`: previously the inventory
rename form could submit empty notes simply because the listing omitted them,
silently clearing an existing product note. The new listing field keeps the
manual editor from losing that user data and allows it to guard the note.
They continue to show the translated conflict message and keep the form open.
We intentionally use a *conservative* complete editor snapshot rather than
silently writing unrelated fields from a stale form.

**Guarded inventory edit (opt-in):** `inventory_update` now accepts a map
`expected_inventory` covering quantity, location, expiry date, vacuum seal
and manual-expiry flag. Under SQLite `BEGIN IMMEDIATE`, the current row is
**read again** before modification. A mismatch returns HTTP 409
`inventory_conflict` and rolls back the entire transaction, including
quantity, move and adjustment ledger changes. Missing rows return 404.
The refresh of the baseline inside the lock also makes the adjustment ledger
consistent for older clients which omit the new parameter. A blank expiry
and a SQL NULL expiry are treated alike. Existing rate limiting, shelf-life
learning, HA hooks, and stock-to-shopping sync retain their original paths.

The inventory edit modal captures its snapshot when the editor opens and
shows a localized conflict message on 409 without closing the form or
patching the local stock cache. All six supported locales have the message.
No new database column, migration or feature setting is required.

**Actual regression evidence:** The isolated HTTP suite tests rejected
brand/notes overwrites, malicious snapshot columns, quantity conflicts,
location changes, expiry-date conflicts, refreshing a stale editor and
legacy updates without snapshots. A second integration suite runs two
independent PHP processes against the same synthetic SQLite WAL database.
Five simultaneous note edits yield exactly one success and four rejections;
five simultaneous quantity edits do likewise. The test also checks that the
transaction ledger contains precisely one quantity correction and that
`PRAGMA quick_check` succeeds. A real-browser Safari/PWA test remains
outstanding, but actual UI handlers are exercised in Node with a DOM stub.

**Scope limits / known behavior:** The opt-in guard is a per-row conflict
detector, **not** a general merge or retry engine. Different fields edited
in parallel can still require a manual refresh if the form sends the full
original snapshot. The manual inventory editor's combined title-and-quantity
operation is now atomic on this fork (see section below). Offline queued
writes and third-party clients without snapshots retain the existing
last-write-wins behavior; no silent switch to a new offline conflict policy
was made. Product variants, true Bring! transport,
simultaneous deletion/merge and iOS Safari remain only partially tested.


## Atomic manual inventory editor: product title + stock (2026-10-31)

**Previous confirmed risk:** the inventory editor sent `product_save` to rename
the product and then `inventory_update` to change stock. If the second request
failed or rejected a stale snapshot, the product title persisted but stock did
not. Retrying blindly could produce unexpected partial changes.

**Narrow new opt-in path:** `inventory_update` accepts an optional
`product_edit: {name, expected_name, expected_fields?}`. The existing manual
inventory editor now sends **one** inventory_update request with this block
when renaming while changing stock, plus its existing
`expected_inventory` snapshot. Every combined request must provide that
stock snapshot. SQLite's existing `BEGIN IMMEDIATE` covers the product's
rename, title-derived `shopping_name` and `kind`, stock change, associated
movement/quantity ledger, and unit/package update. A stale product or
inventory edit returns HTTP 409 and rolls the transaction back. Busy errors
continue to be clean HTTP 503 rejections without any partial commit.

For manual titles, the new path reuses the normal
`mergeIncomingProductFields()` normalization (user-locked casing and genre,
shopping title, category and language switches). It updates *only*
title-derived columns; brand/notes/other metadata remain untouched, while the
usual inventory_update code applies stock/packaging/expiry semantics.

**Structural merge protection:** the ordinary catalog product_save action
can merge duplicate IDs; this cross-product reconciliation must not run
incidentally inside a stock correction. The combined manual editor **rejects
a potential duplicate name with HTTP 409** instead of deleting/reparenting
other inventory rows. Standalone product_save/scanner/catalog workflows retain
their previous merge logic, as does the separate product-info editor.

**Tested (disposable SQLite, two real PHP servers):** simultaneous edits of
the same product/stock pair commit only once and leave a matching name,
quantity and transaction ledger. A forced SQLite trigger abort *after the
product rename UPDATE, before the inventory UPDATE* proves the title is
rolled back too. Stale-product, stale-stock, mismatched-product-ID and
missing-snapshot requests leave every record unchanged; legacy inventory
clients without the new block continue to work. The actual JS editor
handlers are tested for single-request success and translated conflict
errors. All relevant unit/expiry, shopping mode and multi-client regression
tests continue running in required CI. PWA asset stamp and service-worker
cache key were versioned together.

**Unverified:** an actual iPhone Safari background-offline replay, external
Bring! network behavior, semantic product-family consolidation under a
simultaneous rename, and multiple-device UI interleavings that a synthetic
HTTP test cannot reproduce. No configuration switch, database migration,
or production deployment was performed.


## Safari / installed PWA offline-write conflicts (2026-10-31)

**Real issues reproduced from the old code:** the queue silently discarded its
oldest unsent change after 100 entries; Safari/localStorage quota, denial, and
JSON corruption were swallowed, so the UI could optimistically report success
although no write was queued. Replay rewrote the entire old array *after*
network requests, potentially deleting new operations queued meanwhile and
replaying already-acknowledged requests after iOS background suspension.
An offline-created stock row was assigned a UI-only negative ID; later edits
could not resolve that ID, partly because the real `inventory_add` API reports
`inventory_id`, **not** `id`.

**Current behavior (opt-in offline mode, not a config change):**

- A queue remains capped at 100 but never evicts unsent data: the 101st write
  fails *visibly* without an optimistic cache change. Corrupted/blocked storage
  fails closed; Safari quota/private-mode errors are not suppressed.
- Queue mutations and network replay use the Web Locks API when available,
  including when separate PWA tabs queue simultaneously. No lock support
  (e.g. Safari Lockdown Mode / older WebKit) falls back to per-tab serial
  locking only; **cross-tab writes are not guaranteed in that fallback**.
- Each queued op gets an ID; successful server acknowledgements are removed
  and persisted **immediately**. A suspended page can resume without resending
  earlier acknowledged operations. Legacy queue entries without IDs are
  supported; if storage is too full to migrate IDs, they can still drain.
- Offline-created inventory rows keep the *same negative ID* in their UI cache
  and queue; after `inventory_add` returns `inventory_id`, dependent edits
  are remapped to the real database ID before sending.
- An HTTP 409 product/inventory conflict, a 200 response with
  `shopping_remove: removed=0`, or an **ambiguous network/server response**
  stays queued with `_needsReview`. A later edit to the same row cannot
  overtake that failed operation. Confirmed database_busy 503 may retry.
- A visible banner remains even after reconnect for unsynced operations.
  The review modal shows pending actions and lets the user copy them, or
  explicitly discard/authorize retry **after** checking live stock. A missing
  HTTP response can mean the server actually committed, so a retry warning
  explains that an inventory addition could be counted twice.
- The original online API paths, settings, auto shopping, internal/Bring!
  modes, scanning, unit conversions, product variants, and existing legacy
  saved queue format are not intentionally changed. No database migration.

**Verification:** `scripts/test-pwa-offline-queue.js` runs the actual JS
queue, offline API and replay in isolated JS contexts with a simulated
Safari storage quota failure, corrupted storage, 100+ entries, two simultaneous
tabs using Web Locks, suspension mid-sync, ack-storage failure, 409 conflicts,
legacy queue migration under quota and clean-review banner. A second test
(`scripts/integration-offline-replay-conflicts.js`) uses the **real HTTP API
and disposable SQLite DB**: an offline stale update cannot overwrite an online
winner, a PWA reload cannot replay its 409, unrelated items still sync, new
inventory rows and dependent edits resolve through `inventory_id`, and a
server-committed `inventory_add` with a deliberately lost network response
is *not automatically repeated* on reload. These are required CI checks.

**Important limits (not claimed as solved):** These are **simulated** Safari
tab/lifecycle tests, not a physical iPhone, Safari Web Inspector trace or
real background WebKit suspension. The user's device must be tested for
Home-Screen PWA and normal Safari, quota/eviction, offline cache startup,
Lockdown Mode, relaunch, and actual Wi-Fi switching. Safari may evict local
data; the application cannot recover a queue already removed by the browser.
A request that commits on the server but whose reply disappears is now
held for human review (rather than automatically duplicated); full
automatic exactly-once processing of such requests still requires an
idempotency key enforced by the server. If Safari fails to persist the
queue *after* receiving a successful reply, the tab stops replay but a
later restart can remain ambiguous. Do not represent this as guaranteed
exactly-once delivery or guaranteed persistence on an iPhone.

References:
- WebKit storage rules: https://webkit.org/blog/14403/updates-to-storage-policy/
- WebKit ITP Home-Screen exemption: https://webkit.org/tracking-prevention/
- Web Locks support and Safari exceptions: https://web-platform-dx.github.io/web-features-explorer/features/web-locks/


## Server-enforced idempotency for offline inventory additions (2026-10-31)

**Priority/compatibility:** Only replayed `inventory_add` calls that opt in via
`_offline_op_id` are deduplicated. Scanner, catalog, Bring!, internal shopping
`plan` and `trip`, and legacy API clients omitting the key retain existing
semantics. Existing tables are unchanged; the startup migration creates one
small `offline_write_receipts` table, without any user setting or feature
toggle. Each receipt stores an opaque operation key, action name, SHA-256
request fingerprint, and a small response JSON. There is no silent expiry,
because an iPhone could reconnect after months offline.

**Atomicity:** The receipt INSERT is in the *same SQLite BEGIN IMMEDIATE
transaction* as the inventory row, product unit/packaging and transaction
ledger changes. A committed receipt proves one completed core write.
The second same-key request reads the stored successful response (including
the same `inventory_id`), adding `idempotent_replay:true`. A reused key with
different request fields returns 409 `idempotency_conflict` and does no
new inventory write. Invalid IDs return 400. A cheap early receipt lookup
avoids catalog consolidation on normal replays; an obligatory lookup **under
the write lock** guarantees deduplication when multiple clients arrive
simultaneously. Existing requests without `_offline_op_id` are unchanged.

**Postcommit external synchronization:** Bring!/shopping reconciliation still
happens after the inventory transaction, so a network failure cannot make
an already committed stock addition look rejected. Receipts are first saved
with `shopping_sync_pending:true` in the atomic commit; after reconciliation
the stored response is best-effort refreshed. An abrupt crash between core
commit and external sync means the receipt protects stock against duplication,
**but it cannot guarantee that the Bring! network reconciliation completed**.

**Client:** Offline PWA adds send their persisted `_opId` as the
`_offline_op_id` request field; other offline actions omit it. Ambiguous
responses still remain in the visible review queue for backward compatibility
with older API deployments. Once users compare server state and intentionally
retry, the same operation key safely returns the stored receipt rather than
another stock increment. A successful replay still resolves its negative
temporary row ID from `inventory_id`.

**Regression evidence:** `scripts/integration-idempotent-offline-add.py`
uses two independent PHP servers and a disposable shared SQLite database.
Sequential and eight-way parallel same-ID replays create exactly one stock
increment and one ledger entry; changing quantity with a reused key yields
409. A test-only SQLite trigger failing the receipt INSERT **after stock
and ledger writes** verifies both of those writes roll back, too. The real
PWA-replay HTTP integration test also simulates a successful server commit
followed by loss of the response and verifies an intentional identical
retry returns `idempotent_replay:true` without duplicating inventory.

**Explicit limits:** `inventory_update`, `inventory_use`, delete, spend
and shopping writes are not yet server-deduplicated. They remain conflict-
reviewed on ambiguous network outcomes rather than automatically resubmitted.
Catalog product consolidation runs before the inventory transaction on the
original path; it is not newly made part of this receipt atomicity guarantee.
A manual iPhone Safari/Home-Screen PWA test is still outstanding; see
`docs/STABILITY-SAFARI-MANUAL-TEST.md`. No production installation changed.


## Safari cached-offline view can stay stuck after a live reconnect (2026-10-31)

Reproduced a specific state mismatch: Safari can fire its browser-native
offline event, and the delayed network overlay enters the cached PWA UI
(`_offlineMode=true`) *before* the API heartbeat reaches the server-offline
failure threshold (`_serverOffline=false`). When the user reconnects, the
heartbeat's first successful ping was previously an online→online no-op and
never called `_exitOfflineMode()`. The PWA remained in cache mode despite
a healthy server. The state-unchanged branch now calls restore only on an
**actually successful** heartbeat (`_heartbeatFailures===0`) and an active
cached offline UI. Failed first-attempt pings must not force premature
reconnect. Safari background/foreground and `pageshow` events trigger an
immediate connectivity check when needed, rather than relying solely on
potentially suspended timers or the browser's `online` event.

Regression coverage: `scripts/test-heartbeat.js` exercises native
offline-before-heartbeat, failed retry threshold, successful unchanged state
and duplicate restore suppression, plus Safari `pageshow` and
`visibilitychange` event callbacks. No data or offline queue is cleared.
The new cache version is published only to the isolated HTTPS staging app
after the branch's tests pass; no production update.


## Offline review popup after final conflict discard (2026-10-31)

An iPhone PWA conflict against an online Mac writer correctly preserved the
Mac's stock quantity, but resolving the only queued conflict exposed a UI bug:
the discard flow immediately re-opened the review dialog even though the
queue was empty. The leftover modal showed only the harmless diagnostic
"copy pending changes" button. Independently, _renderOfflineBanner always
showed an online banner, even after the queue was emptied.

The review dialog now closes automatically when a successful verified read
has no pending operations. The online banner is hidden for an empty readable
queue, while genuine unreadable/blocked Safari storage remains visibly
flagged. No automatic retries, data discards or stock updates were added.
The regression script simulates the exact final-conflict discard sequence and
asserts the modal closes and the banner disappears. A user-confirmation is
still required to discard any actual pending action. This is a staging-only
browser update; real inventory and production stay unchanged.
