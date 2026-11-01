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

## New manual test: conflict choices on two devices (2026-11-01)

Use ONLY the separate HTTPS Safari staging instance and the synthetic demo:

1. Confirm both Mac and Home Screen PWA show 8 packages of the demo product.
2. Disconnect iPhone and Wi-Fi. Mac updates 8 to 10 online. iPhone changes
   cached 8 to 9 offline and then reconnects via Tailscale.
3. Open Review pending changes. The current server stock should read 10,
   and the iPhone offline input 9. Select Keep server stock (recommended)
   and confirm. The window and alert should close; both devices stay at 10.
4. For a new quantity-only conflict, inspect both explicit preview choices
   before deciding. Updating the Mac again before the decision must cause a
   revision conflict, not overwrite its new value.
5. Combined rename/expiry/location changes must NOT expose automatic quantity
   rebase options. Unsupported operations remain for manual review.
6. If a confirmation loses its network reply, reopen the review and use the
   confirm/recheck button. The saved receipt key must be reused. Do not wipe
   storage, delete the PWA or send a second, new stock write.

## Product detail "Dispose" versus "Remove from inventory" (2026-11-01)

Work exclusively with synthetic staging inventory, never production:

1. Open the demo item from the pantry (tap the inventory row). The detail
   shows "Entsorgen" (trash icon) among four primary actions, plus a separate,
   smaller "Aus Inventar entfernen (keine Entsorgung)" control beneath.
2. Tap the trash icon. The disposal quantity selector appears, not a generic
   delete confirmation. If there are several storage locations, the tapped
   inventory row's location must be preselected. Tap X/cancel and verify
   quantities are unchanged.
3. Swipe the same inventory row left and choose "Entsorgen": the exact same
   disposal flow must appear. Pick a harmless amount only when explicitly
   testing a real waste transaction; the dialog must ask the reason first.
4. Cancel the inventory-only removal confirmation: no operation is sent.
   When testing confirmed removal, use a separate disposable test row (not
   the user's sample stock); "Aus Inventar entfernen" must not ask for a
   waste reason, and use/waste statistics must not increase.
5. Verify both on installed Home Screen PWA and Safari online. For offline
   use, confirm the selected waste reason persists in the local queue
   before syncing; never clear website data while an operation is pending.


## iPhone pantry tap and grams-per-package display (2026-11-01)

1. On the isolated Safari staging PWA, inspect the demo item in Vorrat.
   Its "Vorratskammer" pill must remain one unbroken word on iPhone.
2. A **single short tap** on the item opens the product detail sheet. This
   contains the visible trash-bin "Entsorgen", which opens the waste form.
   Cancel before saving. No bin icon is shown in the narrow inventory row.
3. Swipe the same item left: Use/Dispose chooser still appears. Swipe right:
   edit modal still appears. Vertical scrolling does not trigger either.
4. In edit modal the unit list must say "Pkg (Packung)", not the duplicated
   "Packung (Packungen)". Leave quantity and unit untouched.
5. In Use/Consume screen the demo at 14 packs of 690g per pack shows
   "14 Packungen (690 g je Packung)" under the item and
   "Vorratskammer: 14 Packungen (insgesamt: 9.660 g)" under Verfügbar.
   This is a labeling test; neither amount is a different stock quantity.
6. Do not consume/dispose/remove the product during this visual check.
   Both Mac and phone must continue to show 14 packages.


## Consistent disposal label + compact German package unit (2026-11-01)

All visible German disposal actions (product-detail trash, swipe-left
chooser, stale-stock CTA and status recommendation) must say "Entsorgen".
Swipe hints also explain that a tap opens **Details** (rather than
consuming automatically). The quantity form says "Wie viel entsorgen?",
and the dashboard count labels disposed products consistently. This is
purely localized copy: both entry points already go to the same
reasoned-waste action, inventory_use with waste notes.

In the product edit unit selector and in narrow quantity badges, the
German abbreviation is now "Pkg" **without a period**, consistent with
"Stk"; the internal stored unit remains "conf". Verify that after loading
this version in Safari once, the installed iPhone PWA shows the new words.
No stock mutation is needed for this UI test.
