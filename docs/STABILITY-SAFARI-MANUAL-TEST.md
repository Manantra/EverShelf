# EverShelf — iPhone Safari and Home-Screen PWA acceptance protocol

**Status: not yet run on physical iPhone.** CI simulates Safari storage and
relaunch, and tests the real HTTP API with disposable SQLite data.

## Safe prerequisites

Use a dedicated HTTPS staging origin, synthetic product names and a separate
SQLite database; do not connect to the household production instance, Bring!
account, scheduled tasks, or notification service. Save a staging DB snapshot.
Record iPhone/iOS version, Safari or installed Home-Screen PWA, Lockdown Mode,
available space, tested commit SHA, and service-worker cache version.
Never include real credentials or private shopping lists in test reports.
Have a second device on the same staging database and read-only SQL access.

## Acceptance tests — run in normal Safari AND installed PWA

1. Offline enqueue and reconnect: open a synthetic product; switch to Airplane
   Mode; change one stock quantity; return online. The local UI must say pending.
   Verify exactly one stock change and matching transaction entry.
2. Two-device conflict: A loads a product, goes offline; B modifies the same
   row online; A edits stale stock and reconnects. B's stock wins; A's action
   remains in the review list with no unintended ledger entry.
3. Background suspension: queue two independent updates; switch apps and lock
   the screen; reopen the PWA and reconnect. Neither update disappears nor
   applies twice. Repeat after closing/reopening Safari.
4. New row and dependent edit: add stock offline, then edit the temporary stock
   row while offline. On reconnect the edit uses the new inventory_id and the
   resulting quantity and ledger reconcile.
5. Lost HTTP response: ONLY on an isolated staging proxy, discard the response
   after the server commits a synthetic inventory_add. Compare server stock;
   explicitly retry the same stored operation ID. The server should return
   idempotent_replay=true with identical inventory_id, without a second write.
6. Multiple tabs: queue two independent changes simultaneously from two
   Safari tabs / supported PWA windows. Confirm both remain. If Web Locks is
   unavailable (older WebKit or Lockdown Mode), record that cross-tab safety is
   not guaranteed rather than calling this test passed.
7. Storage full: fill a disposable offline queue to 100; attempt item 101.
   It must be visibly rejected; all prior operations must remain unchanged.
   Safely simulate quota denial only on staging, never erase real browser data.
8. Conflict review: inspect, copy, explicitly retry or discard a synthetic
   pending action. Both actions must demand user confirmation; conflicted
   actions never automatically overwrite the newer server value.
9. PWA service-worker update with pending work: update the test deployment,
   relaunch. Queue data must survive the update.
10. Repeat core stock scenarios for internal, plan, and trip shopping modes.
    Test actual external Bring! only with separately isolated credentials.

## Database pass criteria

SQLite PRAGMA quick_check must report ok. For each test, compare inventory,
transactions, and offline_write_receipts. A duplicated ID with identical
payload must return the same recorded inventory_id; the same ID with
different quantity must return HTTP 409 idempotency_conflict. A failed receipt
write must roll back stock and transaction ledger.

Do not deploy until these real-device results are captured. WebKit may evict
locally queued data under storage pressure; this procedure does not guarantee
data recovery after the browser has already deleted local storage.
