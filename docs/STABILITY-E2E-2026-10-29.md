# Isolated HTTP/SQLite integration test — 2026-10-29/30

## Environment and isolation

- Fork branch: `stability/reliability-baseline`
- Checked-out source: dedicated local development checkout
- Running test server: standalone PHP 8.3 CLI built-in web server,
  listening on **127.0.0.1:18080 only**
- Test filesystem: independent copy of the fork, **excluding** `.git`,
  `data/`, `logs/`, dependencies and any existing `.env`
- Database: **fresh** `data/evershelf.db` in the test copy
- Test-only `.env`: `EVERSHELF_SANDBOX=1`, `SHOPPING_MODE=internal`,
  `AI_ENABLED=false`, `BARCODE_OFFLINE_ENABLED=false`,
  `REPORT_ENABLED=false`; no third-party credentials

**Safety:** no production database, domain, SSO tokens or API credentials
were copied. The test harness refuses non-loopback URLs or a sandbox root
without its explicit `EVERSHELF_SANDBOX=1` marker. All product and shopping
data in the test database are artificial.

**Not a Docker deployment:** the available Linux test host has no Docker
daemon; the available macOS Docker CLI also had no running daemon. This
provides actual HTTP/API/SQLite execution of the fork, but not Apache,
the shipping container, or its cron configuration. Docker parity remains
a separate follow-up.

## Verified bugs via real HTTP + independent SQLite reads

### Case-only manual product rename silently rewritten

1. `POST product_save` with `name=Passierte tomaten`,
   `name_user_set=1`: HTTP 200.
2. `POST product_save` with the same ID and
   `name=Passierte Tomaten`, `name_user_set=1`: HTTP 200 and
   `success:true`.
3. A **separate SQLite read** still returned `Passierte tomaten`.

**Root cause:** `mergeIncomingProductFields()` unconditionally invoked
`productTitleCapitalize()`, which *lowercases all letters except the
first*. User-locked names went through the same normalization, contrary
to the user's explicit capitalization.

**Fork-only fix:** a distinct `productTitleCapitalizeUserName()` helper
capitalizes only the first character and preserves the remaining typed
case when `name_user_set=1`. Catalog-driven names still use the previous
normalizer. The earlier test expectations for auto-normalized names remain
unchanged.

**After fix:** `product_save`, `product_get` and independent SQLite
all consistently return `Passierte Tomaten`. New PHP regression
`scripts/test-user-title-casing.php` verifies case-only renames,
catalog refresh of a locked title, initial lowercase and ordinary
non-user-locked normalization.

### Updating a nonexistent product ID falsely returned success

1. `POST product_save` with `id=999999999` and a valid name.
2. Before the fix: HTTP 200 `{success:true,id:999999999}`.
3. `GET product_get&id=999999999`: HTTP 404.

**Root cause:** the update path did not check whether `loadProductRow()`
found any row; `executeProductUpdate()` silently updated zero rows.

**Fork-only fix:** missing IDs now return HTTP 404 and
`{success:false,error:"product_not_found"}` before the attempted
update. Included in the HTTP integration regression.

## Repeatable HTTP regression suite

`scripts/test-http-persistence.py` (Python standard library only)
verifies the following on a fresh or dedicated test database:

1. HTTP `ping`
2. Create a uniquely named synthetic product
3. Case-only manual rename over HTTP
4. Independent `product_get` and direct SQLite persistence check
5. Nonexistent product ID returns HTTP 404 and no success
6. Add a shopping-list row, reload, remove with `purchased:false`,
   reload, then verify a second delete reports zero removed
7. Add two packages to inventory and verify an independent SQLite read
8. SQLite `PRAGMA quick_check`

The shopping-list fixture is a synthetic item **not matched to stocked
products**. A preceding test used a family already covered by inventory;
EverShelf appropriately pruned that row on a list read. The test now
separates that behavior from deletion semantics.

Example:

```bash
python3 scripts/test-http-persistence.py \
  --sandbox-root /path/to/dedicated/EverShelf-stability-sandbox \
  --base-url http://127.0.0.1:18080
```

Create the sandbox root and explicit marker **before** running:
`printf 'EVERSHELF_SANDBOX=1\nSHOPPING_MODE=internal\n' > .env`.
Never point the harness at a shared or household installation.

## Background synchronization smoke test

The real `api/cron_smart_shopping.php` ran in CLI mode against the
separate test database with external integrations disabled. Its output
reported `0 items cached`, internal sync `no_cache`, and successful
database cleanup/backup. The data remained accessible and
`PRAGMA quick_check` returned `ok`.

**Limit:** no predicted restock item existed in the synthetic dataset.
This test **does not yet prove** that a dismissed depleted product remains
dismissed after every auto-add/sync path. Add fixtures containing a
realistic depletion/consumption history before declaring that issue fixed.

## CI

`.github/workflows/ci.yml` now includes the entire PHP regression suite,
the JS write-acknowledgement test, translation audit, an ephemeral
loopback-only PHP HTTP server, the HTTP persistence regression, a cron
smoke test and a DB integrity check. The regression job is a mandatory
dependency of the `develop` to `main` auto-merge job.

Local execution confirmed the tests work; GitHub Actions must still
be enabled/verified for the newly forked repository. Do not report
GitHub CI as green until actual runs appear and finish successfully.

## Future coverage

- Recreate a depleted-product dismissal and run both cron and live sync
- SQLite WAL concurrency, simultaneous clients, duplicate removal races
- Recovery after an interrupted write, service-worker cache and offline replay
- Docker/Apache startup, image rebuild and real Safari/iPhone browser tests

## Additional HTTP/dismissal integration test (2026-10-30)

A new script, `scripts/integration-shopping-dismiss.py`, uses the loopback-only,
explicitly marked sandbox and actual application functions. It creates an
unstocked synthetic product, supplies an urgent smart-shopping cache item and
verifies that `internalShoppingAutoAddCritical()` adds it. The item is then
removed using the real `shopping_remove` HTTP API with `purchased:false`.

Because this removal invalidates the smart-shopping cache, the test deliberately
**restores the same stale urgent forecast** to emulate a race with cron. The
sync must not recreate the dismissed item. It also invokes
`bringQuickSyncProduct()` (the live stock-change path), repeats cron sync and
confirms via fresh HTTP reads and a separate SQLite connection that the item
remains dismissed, with the decision stored in `app_settings` and database
integrity still `ok`.

**Result:** local sandbox **passed** every check of this sequence. The test now
runs in the CI's isolated HTTP/SQLite job. This verifies one representative
critical/depleted family; multiple products, partial inventory, multi-device
conflicts and full five-minute cron schedule remain future coverage.

**Repeatability improvement:** shopping-family names in the new integration
fixture now use a random alphabetic token plus a specific suffix, so the
generic `name` and specific `raw_name` differ, mimicking the original
`Passierte` / `Passierte Tomaten` mismatch. Each run receives a unique
family, so an earlier run's intentional dismissal cannot suppress the next
run's otherwise valid auto-add. Repeated local runs both passed.
