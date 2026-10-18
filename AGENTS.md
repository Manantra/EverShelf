# AGENTS.md — EverShelf working guide

> Purpose: let an AI agent (or a new dev) be productive **without** loading the
> two giant files (`api/index.php`, `assets/js/app.js`) in full. Read this first,
> then jump to just the lines you need using the generated indexes.

## What this project is

Self-hosted **pantry manager** (PHP 8 + SQLite + vanilla-JS PWA). No framework,
no build step required at runtime (minify is optional). Everything is served from
the repo root: `index.html` (SPA) talks to `api/index.php` (single router).

Repo root = `/var/www/html/dispensa` (git remote: `dadaloop82/EverShelf`).

## The 30-second mental model

```
Browser (index.html + assets/js/app.js)
        │  fetch  api/index.php?action=<name>   (JSON, POST/GET)
        ▼
api/index.php   → switch($action) → handler fn → SQLite (data/evershelf.db)
        ▲                                         ▲
        │                                          │
  api/lib/*.php (env, security, ai, health, weather, seasonal…)
  api/cron_smart_shopping.php (CLI cron) ──────────┘
```

- **All** HTTP actions are dispatched by one `switch ($action)` in
  `api/index.php` (line **898**). See `docs/INDEX-actions.md` for action → handler.
- Frontend is one file `assets/js/app.js` (~26.2k lines, 933 top-level functions).
  See `docs/INDEX-app-js.md` for function → line.
- Backend is one file `api/index.php` (~19.5k lines, 408 functions).
  See `docs/INDEX-api-index.md`.

## Golden rules (conventions already in the codebase)

- **Language**: code, comments and docs are **English**. UI strings live in
  `translations/*.json` (IT/EN/DE/FR/ES/ZH) and are used via `t('key')` in JS /
  `data-i18n="key"` in HTML. Never hardcode a user-facing string.
- **Errors**: never emit HTML before JSON. `api/bootstrap.php` disables
  `display_errors`. Return `{"success":false,"error":"..."}`; wrap handlers in the
  existing global try/catch and `EverLog`.
- **Logging**: use `EverLog::info/warn/error/debug/exception/request` (`api/logger.php`).
  Do **not** use `error_log`/`var_dump`. Put the human-readable words in the message
  and the stable machine key in the context:
  `EverLog::warn('API pairing code', ['event' => 'api_pairing_code', …])`. The pairing
  code must stay greppable with the command the docs advertise
  (`grep -i "pairing code" logs/evershelf_*.log`) — a snake_case-only message made
  that command silently return nothing.
- **Product titles lead with their genre.** `products.name` is user data and the app
  now owns the first word of it: a new/renamed product goes through
  `mergeIncomingProductFields()` → `productKindApply()` (`api/lib/product_kind.php`),
  which prefixes the genre the label usually omits (`"Fiori di latte"` →
  `"Yogurt Fiori di latte"`) and stores it in `products.kind`. Cheap first — the
  curated dictionary (`evershelfShoppingPhraseMap`/`...KeywordMap`, the *same*
  dictionaries `computeShoppingName()` uses) — and the AI only when the genre word
  does not lead the name, i.e. when the dictionary cannot tell a yoghurt from a
  cheese. One paid word per signature (`data/product_kind_cache.json`), never two
  prefixes on the same title — neither when the genre is already there in another form
  (`Tarallini`/`Taralli`, `Pera Italiana`/`Pere`, `Kaffee`/`Caffè`, `Italia
  Zuccheri`/`Zucchero`) nor when the title already opens with the genre stored in
  `products.kind`: a maintenance pass must replay without drifting a single title
  (`productKindNameAlreadyHasKind()`, `productKindStartsWithWord()`). The same choke
  point also owns the *case* of the title: `productTitleCapitalize()` raises the first
  letter (`"latte fresco"` → `"Latte fresco"`) and nothing else — the rest of the case
  is kept (`NUTELLA` stays `NUTELLA`, while a brand spelling such as `iPhone` becomes
  `IPhone`: the rule is mechanical so it cannot disagree with itself) and a title opening
  with a digit or an emoji is left alone. It is applied in `mergeIncomingProductFields()`
  **outside** the genre guard, so `PRODUCT_KIND_PREFIX=false` switches off the prefix
  only, never the spelling, and it is idempotent, so the maintenance pass can replay it.
  `normalizeProductName()` (api/index.php) is **not** the storage normalizer: it
  lowercases a *copy* to compare two products in duplicate detection. One vocabulary,
  two consumers — `products.kind` (genre embedded in the title: precise, may legitimately
  stay empty) and `products.shopping_name` (the buyable word of the list/Bring!: historical,
  may be a raw token such as "Potato") are **two columns on purpose**, both reading that one
  dictionary through `productKindFromDictionary()`; never merge them and never add a second
  dictionary, or the two will disagree again.
  The same choke point also owns the **number**: the stored title is the *singular*
  (`productKindSingularizeName()`: `"Uova medie"` → `"Uovo medio"` — only the genre leading
  the title moves, the adjective run right after it follows, a variety name never does, and
  a title opening with a quantity is left alone), while the *plural* is derived where a
  count is shown, and only for units that count pieces (`productKindUnitCountsPieces()`,
  `productNameForPieces()`): the pantry read hands the client a `display_name` (`"3 Uova
  medie"`), so `500 g Pasta` stays a mass and never becomes `500 g Paste`. The two are one
  round trip (`singular → plural → singular`), which is what lets the maintenance pass
  replay. `products.shopping_name` is deliberately **not** rewritten by that pass: it is
  the buyable word of the list, and the shopping list buys `Grissini`, not one `Grissino`.
- **Automatic favourites respect the user.** `api/lib/auto_favorite.php` promotes only
  the absolute top `AUTO_FAVORITE_TOP_N` (default 3) products by consumption that also
  clear `AUTO_FAVORITE_MIN_USES` inside `AUTO_FAVORITE_WINDOW_DAYS`;
  `products.favorite_user_override` records a manual unstar so the rule never re-adds
  it. Favourites are only ever added, never removed by the rule.
- **Security**: every POST action goes through the CSRF guard and the API-token
  check. New action → add it to the relevant allow-lists in
  `api/lib/security.php` (`evershelfPublicActions`, `evershelfMutatingGetActions`,
  `evershelfDemoReadOnlyActions`, …) only when intended.
  **Never authorise on client-supplied headers** (`Origin`, `Referer`,
  `Sec-Fetch-Site` are forgeable).   `app_bootstrap` only hands out `API_TOKEN` after
  the one-time pairing code (`api/lib/pairing.php`) is presented; a paired device
  shows the live code under Settings → System → Security (`pairing_code` action);
  see `SECURITY.md`.
- **Auth overlays are drawn during startup, above everything.** Pairing is requested
  while `_initApp()` is still running, and `_initApp()` returns early when it cannot
  authenticate — so `#app-preloader` (z-index 200000) is never removed. The auth
  overlays use `EVERSHELF_AUTH_OVERLAY_Z` (`assets/js/core/auth.js`, 400000), which
  must stay above `#app-preloader` **and** `#network-error-overlay` (300000). At the
  `.modal-overlay` default of 200 the dialog is invisible behind the splash and the
  app dead-ends on "API token required".
- **The splash must not lie about the boot.** The rail icons are driven by
  `_preloaderStage(stage, state)` and the stage→check ownership in
  `_PRELOADER_STAGE_OF_CHECK`: a health check nobody owns leaves its icon blinking
  forever, and a stage whose `startup.stage_*` string is missing from any of the six
  locales prints its raw key. New check → give it a stage; new stage → add the markup row
  *and* the six translations. Keep the version chip's `id="preloader-version">vX.Y.Z`
  shape too: `scripts/bump-version.sh` rewrites it (`scripts/test-preloader-stages.php`
  locks all of it).
- **Versioning**: bump version with `scripts/bump-version.sh X.Y.Z` in **5 places**
  (`index.html` badges, `manifest.json`, `sw.js` cache name, `app.js` i18n token,
  `README.md` version badge), add a `CHANGELOG.md` entry and prepend the release to
  the README “What’s new” line (prose, so it stays manual).
  `auto-merge-to-main` + `create-release` read the version from `index.html`.
- **i18n**: never hardcode user-facing strings. Use `t('key')` (JS) /
  `data-i18n*` (HTML) and add the key to **all** locales (it/en/de/fr/es/zh).
  `scripts/i18n-audit.py` (run in CI) fails on used-but-missing keys; give `tl()`
  fallbacks in English. Optional UI strings from PHP should return a `hint_key`
  (+ `hint_args`), not a literal — and so should a whole payload (`tip_key`,
  `reasons[]`): the client resolves it with `t()`. The audit's JS regex is
  literally `\bt\(\s*['"]…`, so a key wrapped in a helper (the local
  `tr(key, fallback)` in `_localizeSmartReason`) or emitted **only** by PHP is
  invisible to it: write the resolution as a bare `t('key')` *and* lock the set
  with a PHP test — `scripts/test-seasonal-match.php` asserts every
  `shopping.seasonal_tip_*` key PHP can return exists in all six locales.
- **Secrets**: never commit keystores, `.env`, or signing passwords. Android
  builds read `keystore.properties`/env; CI reads GitHub Secrets (see `SECURITY.md`).
- **Commits**: Conventional-ish prefixes seen in history: `feat:`, `fix:`,
  `release:`, `merge:`. Work on **`develop`**; CI auto-merges `develop → main`.
- **Assets cache-busting**: when you edit `app.js`/`style.css`, bump the `?v=`
  query in `index.html` and the `sw.js` `CACHE` name (it carries the version *and*
  the stamp letter, e.g. `evershelf-v1.11.13b`). `scripts/bump-version.sh` does both
  and, inside the same day, continues the stamp letter (`20261014a` → `20261014b`)
  instead of reusing it — a repeated `<today>a` leaves the browser on the old CSS.

## 4b. The vocabulary is DATA, the code is language-neutral

Product names, genres, brands, plural/singular forms, stop words, the shopping
dictionaries and the "what does this title mean" rules live in **`api/vocab/<lang>.json`**.
`api/lib/vocabulary.php` is the only algorithm that reads them (tokenizer, word/stem/phrase
match, number forms) and it contains **no product word and no rule about Italian**. The
browser runs the same engine (`assets/js/core/vocab.js`) over
**`assets/js/vocab.generated.js`**, generated from the JSON by
`php scripts/gen-vocab-js.php` (committed, offline-safe).

Adding a language = dropping `api/vocab/<lang>.json` and regenerating. Adding a word =
editing JSON, never a function.

`scripts/test-vocabulary.php` enforces it: the generated file must match the JSON, and a
**ratchet** counts the vocabulary words still left in the logic files — `product_kind.php`,
`vocabulary.php` and `core/vocab.js` are at **0**, the rest may only go *down*. A new
product name in `api/index.php`, `api/database.php` or `app.js` fails the build.

Remaining debt (next batch, same rule): the expiry heuristics in `api/database.php` /
`app.js` and the recipe ingredient groups.



```bash
# PHP syntax check (same as CI)
find api -name '*.php' -exec php -l {} \;

# JS syntax check (CI runs `node -c` on app.js; the rest are local-only;
# mcp-server is ESM → --check, not -c)
node -c assets/js/app.js && node -c sw.js
for f in mcp-server/src/*.js; do node --check "$f"; done

# Android dependency check (build-only, debug APK, no secrets, nothing published).
# It runs on pull requests touching either Android project; dispatch it by hand to
# test a dependency bump on a branch before it reaches main:
#   gh workflow run android-deps-check.yml --ref <branch>

# PHP regression tests — local-only until the CI patch lands (see the note at
# the end of this file), so run them yourself before pushing
php scripts/test-shopping-guards.php
php scripts/test-internal-shopping-cleanup.php
php scripts/test-notify.php
php scripts/test-healthcheck.php
php scripts/test-settings-nav.php      # settings sections ↔ tabs ↔ panels ↔ locales
php scripts/test-setup-assistant.php    # SETTINGS_CHECKLIST ↔ tabs ↔ wizard steps
php scripts/test-i18n-icons.php         # no label prints its icon twice (all locales)
php scripts/test-html-in-text.php       # no HTML reaches a text-only surface (screensaver facts)
php scripts/test-dashboard-panels.php   # dashboard rotation: phases ↔ sections ↔ bar fills, no orphan flags
php scripts/test-product-kind-prefix.php # genre leading every article title (dictionary → cache → AI, no double prefix)
php scripts/test-product-number.php     # singular title in the catalog, plural only in the pantry (piece units)
php scripts/test-product-rename.php     # a scanned title/brand can be corrected, and a rescan cannot undo it
php scripts/test-inventory-add-guard.php # adding to the pantry always has a catalog id (no raw "Product ID required")
php scripts/test-ai-product-match.php   # the AI name matches the RIGHT article (tomatoes ≠ passata), ranking + client filter
php scripts/test-product-quantity-floor.php # a pantry row below 20 g/ml (or 1 pz) is refused; tomatoes count by piece
php scripts/test-shopping-total-canonical.php # one "Spesa stimata" for app, kiosk and Home Assistant (one basket, one key)
php scripts/test-auto-favorite.php      # used-often products become favourites; a manual unstar always wins
php scripts/test-support-monetization.php # optional support: the 2 answers, donation link, parked banner slots, all 6 locales
php scripts/test-qr-encoder.php         # the server-side QR encoder (frozen vectors + format/version information)
php scripts/test-preloader-stages.php   # splash boot rail: stages ↔ health checks ↔ locales, no icon left blinking
php scripts/test-recipe-archive.php     # archive: content_hash dedup, many libero/day, scheduled slots still unique
php scripts/test-env-overrides.php      # a hand-edited .env wins over a stale DB fallback; the read-only fallback still works
php scripts/test-docker-cron.php        # container cron (5 min, www-data) really runs the daily backup; stale-backup warning in 6 locales
php scripts/test-backup-i18n.php        # backup panel: error_key → t() in 6 locales, no hardcoded English fallback

# Translation files must be valid JSON
python3 -c "import json; json.load(open('translations/it.json'))"

# i18n audit: keys used in code must exist in every locale (exits 1 on gaps)
python3 scripts/i18n-audit.py

# i18n value audit: keys whose value is still English (report; --strict to fail)
python3 scripts/i18n-value-audit.py

# Shell scripts (local-only for now, like the test suite)
shellcheck -S warning backup.sh scripts/*.sh

# Bump the version in the 4 touchpoints at once (+ cache-busting stamp)
scripts/bump-version.sh 1.9.2

# Regenerate the cheap code indexes (do this after big edits)
bash scripts/gen-code-index.sh

# Run locally without Docker (needs the php extensions from the Dockerfile)
php -S 127.0.0.1:8080            # then open http://127.0.0.1:8080

# Optional minify (not required at runtime)
npm run build
```

## Where things live (quick map)

| Want to… | Go to |
|---|---|
| Add/change an HTTP endpoint | `api/index.php` switch (line 898) + a handler `function` below |
| Auth / CORS / demo mode | `api/lib/security.php` |
| Config / `.env` read+write | `api/lib/env.php` (`env()`, `loadEnvOverrides()`: a fallback stored because `.env` was read-only carries the value it was saved against, so a later hand edit of the file wins and the stale entry is dropped — `settings_override_stale`), `saveSettings()` (~9293) — guard test `scripts/test-env-overrides.php` |
| DB schema & migrations | `api/database.php` (`initializeDB`, `migrateDB`) |
| AI providers (Gemini/OpenAI/Llama) | `api/lib/ai_provider.php`, `callGemini()` (~8302) |
| Shopping logic | `smartShopping()` (~16000), `shopping_guards.php`, `shopping_sync.php` (shared Bring!/internal sync), `bring_*` fns |
| Recipe archive (chat / generate) | `recipesArchiveUpsert()` + `recipeArchiveContentHash()` in `api/index.php` / `api/database.php`; `recipes.content_hash` prevents clones; `useRecipeIngredient()` / scale X÷Y UI in `app.js` — guard test `scripts/test-recipe-archive.php` |
| Genre in the article title | `api/lib/product_kind.php` + `mergeIncomingProductFields()` (the single title choke point) + `products.kind`; dictionary shared with `computeShoppingName()` — guard test `scripts/test-product-kind-prefix.php` |
| Singular title ↔ plural pantry | `productKindSingularizeName()` (stored title), `productNameForPieces()` + `display_name` in `listInventory()`, `productKindUnitCountsPieces()` — guard test `scripts/test-product-number.php` |
| Automatic favourites | `api/lib/auto_favorite.php` (`maybeAutoFavorite()` on every `inventory_use`), `products.favorite_user_override`, maintenance action `products_apply_auto_rules` — guard test `scripts/test-auto-favorite.php` |
| Correct a scanned title/brand | `showAddForm()` → `_renderAddProductPreview()` / `_commitAddProductRename()` in app.js (`name_user_set` locks the user name) + the AI card `_showAiMatchChoices()` — guard test `scripts/test-product-rename.php` |
| Add to pantry needs a catalog id | `submitAdd()` → `_ensureAddProductRow()` (creates the row with `name_user_set`), `_apiErrorText()` for `error_key` answers, `addToInventory()` in api/index.php — guard test `scripts/test-inventory-add-guard.php` |
| Duplicate products — never merge two different articles | `productNamesSameArticle()` (+ `productNameStem()`): the same name at another number is one row, but a shared **substring is not** ("Pomodoro" ⊄ "Passata di pomodoro"), and the loose head rule needs a barcode. A **loose vegetable never reuses/merges with the pantry's tin of the same word** (`productRowClashesWithLooseProduce()`: `isSoldByPieceProduct()` + the row's category) — used by `collectDuplicateProductIds()` (which DELETES a row) and `findDuplicateProductId()` — the AI-name ranking is `aiProductNameRelevance()` / `aiProductSuggestRank()`, the client drops candidates of another nature with `_aiMatchKeepSameNature()` — guard test `scripts/test-ai-product-match.php` |
| Which category does a name belong to | `guessCategoryFromName()` (app.js) and `guessCategoryFromNamePHP()` (api/index.php) are **one vocabulary written twice**: the PHP one is a PORT of the JS one (same order, same patterns — port every change to both). A bare "Pomodoro"/"Pomodorini"/"Carote" is produce (`verdura`/`frutta`, unit pieces), only the preparations are `conserve` ("Passata/Pelati/Sugo/Polpa di pomodoro"); stems, no trailing `\b` (`pomodor`, `carot`, `mel[ae]`), and accented words close with `(?![a-z])` because the JS `\b` is ASCII-only while PCRE's is not ("Caffè"). An 80-name probe list covering the whole grocery is locked by `scripts/test-ai-product-match.php`, which runs the app's own JS in node against the PHP guesser |
| Minimum quantity of a NEW pantry row | `inventoryMinAddQuantity()` (g/ml 20, kg/l 0.02, pz 1, conf 0.25) enforced in `addToInventory()` (`error_key error.quantity_below_min`), mirrored by `_ADD_MIN_QTY_BY_UNIT` / `_addMinQtyForUnit()` in app.js (`updateAddQtyStep()`, `onAddUnitChange()`, `submitAdd()`); tomatoes are piece produce in `isSoldByPieceProduct()` / `suggestCategoryUnitQty()` — guard test `scripts/test-product-quantity-floor.php` |
| Recorded spend (dashboard) | `spendAdd()` / `getSpendStats()` in api/index.php + `data/shopping_spend.json` (append-only, one amount per spesa session, asked when the session starts); every write is logged (`event api_spend_add`, with the resulting month total) because the ledger has no correction UI — guard test `scripts/test-dashboard-panels.php` |
| "Spesa stimata" of the list (one figure only) | `_computeAllShoppingPrices()` is the only producer: the server-side resolution (`_resolveShoppingPriceItem()` from the smart cache) wins and the caller's quantity is only the fallback; one key `_shoppingListPriceHash()` and `data/shopping_total_cache.json` holds one bucket per key (`_pruneShoppingTotalEntries()`) — guard test `scripts/test-shopping-total-canonical.php` |
| Cron watchdog / notifications | `api/lib/healthcheck.php`, `api/lib/notify.php`, `cron_*.php` |
| `.env` bootstrap / pairing | `api/lib/env.php`, `api/lib/pairing.php`, `app_bootstrap` in `api/index.php` |
| Seasonal produce | `api/lib/seasonal.php` + `data/seasonal_produce_it.json` |
| Health / Fuel mode | `api/lib/health.php` |
| Frontend API wrapper | `api()` in `assets/js/app.js` line **5384** |
| i18n helper | `t()` line ~1198, `loadTranslations()` ~1209 |
| Settings page (sections, accordion, checklist) | `SETTINGS_GROUPS` + `switchSettingsGroup()`, `SETTINGS_CHECKLIST` (~25820), `_initSettingsAccordions()` (~5222), `_openSettingsCardFor()` (~5288), `_checklistNewsItems()` (~25907) |
| Dashboard panels & limits | `DASHBOARD_*_MAX` (~6623), `_dashboardAlertCap()`, `_staleRotationPick()`, `_INSIGHT_PHASES` (~6529) / `_applyInsightPhase()` (~6544); bars are drawn at 0% and filled from `data-target` on reveal — guard test `scripts/test-dashboard-panels.php` |
| Emoji-before-translated-label bug | `iconLabel()` / `_stripLeadingEmoji()`; guard test `scripts/test-i18n-icons.php` |
| HTML printed as text (screensaver fact, alert banner, chooser modal) | `formatQuantity()` returns `<span class="conf-size-info">`; text-only callers use `_formatQtyPlain()` / `stripHtml()`; guard test `scripts/test-html-in-text.php` |
| Splash / boot rail | `#app-preloader` in `index.html` + `_preloaderStage()` / `_PRELOADER_STAGE_KEYS` / `_PRELOADER_STAGE_OF_CHECK` in `app.js` (grey → blink → coloured aura; `is-pending|is-active|is-done|is-warn|is-error`), version chip `#preloader-version`; guard test `scripts/test-preloader-stages.php` |
| Optional support (donations only — **no ads**) | `api/lib/support.php` (decision, payload, donation link, disclosed costs), `api/lib/qr.php` (ISO QR encoder → `?action=support_qr`), `assets/js/core/support.js` (dialog, donation panel; `_renderAdSlots()` is a parked no-op), `.env` keys `SUPPORT_*` — the banner slots and the `ADS_*` / `SUPPORT_BANNERS_AVAILABLE` keys are **commented out on purpose**; guard tests `scripts/test-support-monetization.php`, `scripts/test-qr-encoder.php`, doc `docs/wiki/Support-and-ads.md` |
| PWA service worker | `sw.js` |
| Unraid / containers | `unraid/evershelf.xml` (CA template: the `data` path is the database), `docs/wiki/Unraid.md`, `EVERSHELF_ENV_FILE` → `evershelfEnvFile()` in `api/lib/env.php` — guard test `scripts/test-unraid-template.php` |
| Backup + the scheduler that runs it | `createLocalBackup()` / `restoreLocalBackup()` / `listLocalBackups()` in `api/index.php` (`VACUUM INTO` → `.part` → rename, retention, `data/backup_last_ts.json`); `docker/evershelf-cron` (`/etc/cron.d/evershelf`, every 5 min as `www-data`) runs `api/cron_smart_shopping.php`, which backs up at most once every 23 h when `BACKUP_ENABLED=true` (default); *Settings → 💾 Backup* shows the last one and warns after `_BACKUP_STALE_DAYS` (8); failures carry `error_key`, restore carries `message_key` — guard tests `scripts/test-docker-cron.php`, `scripts/test-backup-i18n.php` |
| Kiosk (Android WebView) | `evershelf-kiosk/` — `KioskActivity.kt` (WebView surface colour, URL normalisation on resume, 5-min page watchdog), `themes.xml` (`windowBackground`), `SetupActivity.kt` (pairing wizard) |

## Anti-patterns to avoid (already present — do not copy)

- Building HTML with string concatenation + `innerHTML` and inline `onclick`
  with un-escaped data. Prefer the existing `escapeHtml`.
- Reading whole giant files to find one function — use the indexes / `grep -n`.
- Writing to `.env` without escaping values (comment/quote/newline safe).

## See also

- `docs/CODEBASE-MAP.md` — deep architecture, DB schema, data files, action catalog.
- `docs/ROADMAP.md` — the product-facing roadmap: what is next, the effort involved, and
  which issues are open for grabs (the pinned #290 is the community entry point).
- `docs/OPTIMIZATION-BACKLOG.md` — prioritized bugs, perf, CI, and technical debt.
- `docs/ARCHITECTURE.md`, `docs/CORPORATE-UI.md`, `SECURITY.md`, `CONTRIBUTING.md`.
- `.clinerules` — the short digest of this file, loaded automatically by Cline.
- `.clineignore` — hides generated/binary/runtime/vendor files from the agent's
  context (gitignore syntax). Update it when a new generated folder appears.

> Audit / review notes are **internal working documents**. They are kept in the
> git-ignored `todo/` folder at the repo root and are intentionally never pushed:
> this repository is public and everything committed must be in English.
>
> This includes the CI improvements that need a `workflow`-scoped PAT to push:
> they live on the local branch `ci-tests-lint` and as
> `todo/0001-ci-run-the-test-suite-lint-every-JS-file-shellcheck-.patch`.
> Apply with `git am todo/0001-ci-*.patch` once a PAT with `workflow` scope is set.
