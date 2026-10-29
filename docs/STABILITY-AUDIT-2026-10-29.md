# EverShelf stability audit — 2026-10-29

**Target:** `Manantra/EverShelf`, branch `stability/reliability-baseline` (forked from upstream `develop`).
**Method:** code reading, full available PHP regression suite, syntax and translation
checks, and sandboxed JavaScript function-level reproductions. **No production
database, private endpoint, or household data was used.** This is a *baseline*,
not a claim that every runtime path has been exhaustively tested.

## Test results

| Check | Result | Method / environment |
|---|---|---|
| Existing PHP regression scripts | **49 pass, 1 fail** (50 total) | Isolated PHP **8.3.6** CLI, extensions loaded from extracted Ubuntu packages |
| PHP syntax | **110 pass, 0 fail** | `php -l` over `api/` and `scripts/` |
| JavaScript syntax | **15 pass, 0 fail** | `node --check` over first-party JavaScript |
| Translation JSON syntax | **6 pass, 0 fail** | Python JSON parser |
| Internationalization audit | **pass** | `python3 scripts/i18n-audit.py` |
| Internationalization value audit | **exit 0** | `python3 scripts/i18n-value-audit.py`; emitted a list of identical text values that may be legitimate |
| Two targeted JS failure-path probes | **2 problematic behaviors reproduced** | `node:vm` with stubbed backend responses; no writes |
| Docker build / boot | **not run** | Docker unavailable on the test host |
| Real-browser/PWA/SSO end-to-end | **not run** | Requires an isolated running instance and browser automation |
| Android/scale/health-bridge build | **not run** | Android SDK/device and build dependencies unavailable |

The tests ran on a disposable clone of the fork, not the deployed image.
The production Dockerfile uses PHP 8.5 whereas this isolated run used PHP 8.3;
version-specific compatibility still requires confirmation.

## Confirmed findings (reproduced)

### S-01 — Failed offline replay is silently marked as synchronized

**Severity: Critical (potential lost edits).**
**Source:** `assets/js/app.js`, `_syncOfflineQueue()` and `api()`.

The API wrapper returns JSON errors (including HTTP errors), rather than always
throwing. The offline replay loop checks only `result._offline`. It then
increments `synced` and removes the queued operation even if the server returned
`{success:false,error:'database_busy'}`.

Sandbox reproduction: a queue with one `inventory_update` operation, stubbed
`api()` response `{success:false,error:'database_busy'}`:
**`reportedSynced=1, remainingQueue=0`**.

**Impact:** under a replayable server rejection, a queued modification can be
discarded without having been persisted. The subsequent toast can claim success.

**Recommended regression:** failing API result must keep the original item in
the queue and not increment synchronized count; test HTTP 4xx/5xx JSON errors,
invalid JSON, network exceptions, and successful replay. Only map temporary IDs
after acknowledged writes.

### S-02 — Shopping item disappears from UI even if the server deleted zero rows

**Severity: High (misleading save/delete acknowledgement).**
**Source:** `assets/js/app.js`, `removeBringItem()`; server
`api/index.php`, `shoppingRemoveInternal()`.

`shoppingRemoveInternal()` can return `{success:true,removed:0}` when no matching
row was deleted. The client checks only `data.success || data._offline`, removes
the item from its in-memory list, and displays a success toast.

Sandbox reproduction: response `{success:true,removed:0}` leads to
**`clientRemaining=0, toast=success`** although the mock server deleted nothing.

**Impact:** a row can apparently disappear, then return on the next server
refresh. This reproduces the *failure mode*, not the complete original
user-specific cause; name matching and auto-add still need separate fixtures.

**Recommended regression:** distinguish delete acknowledgment from no-op;
re-fetch and reconcile server-authoritative state, and verify family matching
for `name` / `rawName` and dismissal status.

### S-03 — Existing product-kind prefix regression fails

**Severity: Medium.**
**Source:** `scripts/test-product-kind-prefix.php`,
`api/lib/product_kind.php`.

Existing test expectation:
`applyProductKindPrefix('Kaffee','Caffè') === 'Kaffee'`.
Actual result: **`'Caffè Kaffee'`**. One test failed; 49 others passed.

**Recommended regression:** repair multilingual genre-equivalence handling
without broadening string matching into false duplicate merges; retain the
existing named test.

## Confirmed process gap

### P-01 — CI does not run the project's PHP regression suite

**Severity: High (regressions may reach a green release pipeline).**
**Source:** `.github/workflows/ci.yml`.

The mandatory CI includes PHP syntax checks, `node -c assets/js/app.js`,
a Docker build/boot smoke test, and JSON checks. No step executes the
`scripts/test-*.php` suite. On `develop`, successful checks can trigger an
automated merge to `main` and release. This allowed the currently failing
product-kind test to go undetected by the required jobs.

**Recommended fix:** add an explicit regression-suite CI job with relevant
extensions and make the merge/release require it, together with a small JS
failure-path test job. Make translation completeness failure exit nonzero.

## Verified source-level risk requiring more testing

### R-01 — Reading the shopping list also writes to SQLite

**Severity: Medium (risk; no race demonstrated).**
**Source:** `api/index.php`, `shoppingGetList()`.

An HTTP `shopping_list` read calls
`internalShoppingNormalizeNames()`, `internalShoppingPruneCovered()` and
`internalShoppingDedupeGenerics()`, which perform updates/deletes.
The API read is therefore not read-only; concurrent cron and UI requests may
change list rows during observation. This design may be intentional. It needs
concurrency and idempotence tests before labeling it a defect.

### R-02 — No end-to-end persistence contract

**Severity: High (coverage gap).**
There are PHP tests for product renaming and shopping dismissals, but no verified
test in this audit that follows the real UI through POST response, persistent
SQLite read, full browser reload, and cron refresh. The reported case-only rename,
reappearing generic shopping title and unexpectedly large recommended amount are
**not yet reproduced in isolation**.

## Suggested baseline order (no new features)

1. **Stop silent loss of offline-queued writes** (S-01): regression test then minimal fix.
2. **Make removal acknowledgments truthful and reload-stable** (S-02).
3. **Make the regression suite mandatory in CI** (P-01), preventing future regressions.
4. **Repair the known prefix assertion** (S-03) with multilingual fixtures.
5. **Exercise shopping-list read/write concurrency** (R-01).
6. **Build a disposable end-to-end test environment** and run Docker, browser and
   PWA tests before any deployment to household data.

## Safeguards

- Do not push production SQLite files, credentials, HTTP auth headers, runtime
  caches or other private household state to this public fork.
- Avoid deploying this audit branch. No application code was modified by the
  audit itself.
- Require at least one independent backup before any planned data migration.
