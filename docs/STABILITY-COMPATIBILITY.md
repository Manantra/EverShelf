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

## Multi-worker inventory concurrency (2026-10-30) — OPEN

**Confirmed on isolated loopback servers, NOT fixed yet.** With two separate
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

**Observed before any source change:** 2 of 36 concurrent adds returned 500.
All 34 acknowledged successful adds were present in inventory (34 packages),
and SQLite `quick_check` was `ok`. Repeating with the connection's SQLite
busy-timeout and WAL pragmas reordered still produced 2 failed requests out
of 60; the attempted configuration edit was **reverted**, because it did not
solve the concurrency problem. No silent stock loss was observed in these
particular runs, but other races, such as partial transactions or conflicting
product edits, were **not** excluded by this experiment.

The current `addToInventory()` performs multiple separate database changes
(product unit, inventory row, transaction ledger, shopping-list cleanup) without
a single all-or-nothing transaction boundary. A durable fix needs to address
atomicity and safe lock retries; blindly retrying the entire request may double
count partially committed changes. Preserve existing expiry-date/location merge
semantics, weight and package conversions, shopping modes and side-effect hooks.

**The stress test is not part of required CI yet:** it intentionally fails
against the current code and uses two independent PHP worker processes. Add it
as a CI gate once a narrow, idempotent transactional fix has been verified.
