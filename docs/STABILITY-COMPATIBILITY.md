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
| `SHOPPING_REMOVE_ON_BUY` | `plan`: keep when stock is insufficient; `trip`: remove after buying | config dispatch tested; `trip` HTTP+SQLite passes; **`plan` HTTP reload currently fails — see below** |
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

## Confirmed, not patched yet: plan-mode partial restock is lost on reload

The isolated `scripts/integration-restock-settings.py` reproducer
creates a synthetic conf product with a planned need of 3 packs and an
auto-cache fixture. It adds a shopping row, then buys 1 pack.

- With `SHOPPING_REMOVE_ON_BUY=trip`: API reports removal and a fresh
  shopping-list read is empty. **PASS**.
- With `SHOPPING_REMOVE_ON_BUY=plan`: the inventory API reports
  `shopping_kept:true`, but the next `shopping_list` read is empty.
  **FAIL (existing bug)**.

**Likely cause:** `inventory_add` invalidates smart-shopping cache;
`shoppingEvaluateFamilyRestock()` then has no consumption estimate for
a newly created product, marks its now-positive stock as covered, and
`internalShoppingPruneCovered()` removes the row despite the
previously computed residual need. The schema already has
`shopping_list.need_qty` and `need_unit`, but a correct fix must also
cover *subsequent* partial purchases and cron cleanup. Changing just
one prune condition would hide the failure rather than make it correct.

The reproducer is intentionally **not yet a required CI step** because
its plan-mode assertion is expected to fail on this branch. The test
runs only against a marked loopback sandbox and restores its temporary
.env configuration after each run. Fix the full lifecycle and then make
this test a required CI gate.

## Current limitations

Shipping Docker/Apache, genuine Bring! account integration, multiple
devices racing, real PWA/offline storage, locale coverage of UI text and
mixed mass/volume shopping family conversion are outside the validated
scope of this quantity patch. Treat them as separate test gaps, not as
permission to change the corresponding behavior casually.
