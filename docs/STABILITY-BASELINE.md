# Reliability baseline (no new features)

**Scope:** make existing EverShelf behaviour predictable, durable and testable.
This is a fork-only working document, not a claim that the issues below have been
reproduced upstream. Do not deploy experimental changes to a household instance.

## Working rules

- Prefer a failing regression test before changing behaviour.
- Make one narrow fix per commit; preserve existing functionality and translations.
- Test against disposable SQLite databases and fixtures. Never run repair scripts
  or destructive SQL against a production database without an independent backup.
- Distinguish persisted server state from optimistic client state. A UI success
  message must not conceal failed or queued writes.
- Use the `develop` branch as the integration base. Keep this work in the fork
  until it can pass the project's existing test suite.
- Follow `AGENTS.md`, including the codebase navigation indexes; avoid loading
  the giant application/router files unnecessarily.
- Do not add user-facing features in this initiative.
- **Mandatory for every future change:** follow [STABILITY-COMPATIBILITY.md](STABILITY-COMPATIBILITY.md).
  Enumerate affected Settings, modes, integration transports, manual overrides,
  units and persistence/sync paths; test intended alternatives before modifying
  shared behavior. Document untested combinations and do not silently alter defaults.

## R1: Product rename must persist (priority: high)

**Observed report:** a manually edited capitalization appeared inside the
product editor but reverted after the editor was closed. A subsequent
read-only SQLite query still showed the old title with `name_user_set = 1`.

**Unresolved:** whether the user submitted the full edit form, whether the
`product_save` request succeeded, and whether another client or catalog
update subsequently reverted the record.

**Acceptance criteria**

- Editing the title and clicking Save results in `product_save` persisting
  the exact user-selected title (subject only to documented normalization).
- An independent `product_get` / `inventory_list` read after save returns
  that title; a full reload does not revert it.
- A failed `product_save` request never produces a success toast.
- A hand-edited title remains protected from later external catalog refreshes.
- Include a regression test for a case-only rename and existing
  `name_user_set = 1`.

**Code landmarks:** `assets/js/app.js` `finishEditProductTitle()` and
`submitEditInventory()`; `api/index.php` `saveProduct()`,
`mergeIncomingProductFields()`; `scripts/test-product-rename.php`.

## R2: Shopping-list deletion must stick (priority: high)

**Observed report:** removing a product from the shopping list was followed by
its reappearance. The generic title was shorter than the original product
title. A database read showed a row with different `name` and `raw_name`.

**Unresolved:** the UI action chosen (bought vs remove/not buying), the actual
server response to `shopping_remove`, the presence of another writer (Bring!
or real-time sync), and which auto-add path recreated the row.

**Acceptance criteria**

- `shopping_remove` with `purchased: false` persists the documented dated
  dismissal and is respected by both cron and real-time sync.
- The removal and reload result are observable from server state, not only
  optimistic client data.
- A generic name and a specific `raw_name` refer to the same item family for
  removal, deduplication and dismissal.
- Normalizing a shopping title is idempotent: a second read must not continue
  modifying the title.
- No repeatedly reappearing row for a dismissed family.

**Code landmarks:** `shoppingRemoveInternal()`,
`internalShoppingNormalizeNames()`, `internalShoppingAutoAddCritical()`,
`bringQuickSyncProduct()`, `scripts/test-shopping-dismiss.php`.

## R3: Shopping quantity sanity (priority: high)

**Observed report:** a shopping-list specification recommended a minimum of
24 packages for an ordinary pantry item. This is an observation, not yet a
confirmed calculation bug: we must inspect the product's unit, pack size,
stock and consumption ledger first.

**Acceptance criteria**

- Unit conversions remain dimensionally consistent (`conf` vs grams/ml).
- Suggested package quantities respect the existing shopping guards.
- A named regression fixture shows why a recommendation is generated; it is
  capped to the expected unit and does not silently mix package counts
  and weight-based consumption.

**Code landmarks:** `api/lib/shopping_guards.php`,
`api/lib/shopping_sync.php`, `scripts/test-shopping-guards.php`,
`scripts/test-shopping-restock.php`.

## R4: Heartbeat should not claim offline on one slow request (priority: medium)

**Observed report:** the browser's `?action=ping` request was aborted by the
application's explicit seven-second `AbortController` timeout; the connection
was restored on a later ping. A **temporary, local-only** mitigation extended
the timeout to 15 seconds and required two consecutive failures. That runtime
patch is **not** part of the fork.

**Acceptance criteria**

- Reproducible automated tests distinguish delayed response, aborted request,
  sustained outage, and recovery.
- Transient slowness does not unnecessarily block the UI; sustained outage
  still triggers the offline state.
- The chosen thresholds are documented and tested rather than changed by
  guesswork.

**Code landmarks:** `assets/js/app.js` `_runHeartbeat()`,
`_setServerOffline()`.

## First investigation sequence

1. Reproduce the rename via the real client and capture the HTTP status and
   sanitized JSON response for `product_save` and subsequent `product_get`.
2. Inspect a disposable database fixture covering the mismatched shopping
   `name`/`raw_name` family and both remove flows.
3. Verify the existing shopping-dismiss tests detect a re-add on both cron
   and real-time sync; extend only where coverage is missing.
4. Investigate quantity conversion with a unit/stock/transaction fixture.
5. Bring the heartbeat change into the fork only with a deterministic test.

**Evidence policy:** no local IPs, private URLs, credentials, API tokens,
household data exports or production database snapshots in public commits.
