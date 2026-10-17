# 🏠 EverShelf

> Self-hosted pantry manager — inventory, barcode scan, AI recipes, less waste.

<div align="center">

**[Website](https://evershelf.site/)** · **[Demo](https://evershelf.site/demo)** · **[Wiki](https://github.com/dadaloop82/EverShelf/wiki)** · **[Changelog](CHANGELOG.md)**

[![MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE) [![PHP 8+](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://www.php.net/) [![Version](https://img.shields.io/badge/version-1.16.0-brightgreen.svg)](CHANGELOG.md) [![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

</div>

```bash
git clone https://github.com/dadaloop82/EverShelf.git && cd EverShelf
cp .env.example .env && docker compose up -d    # → http://localhost:8080
```

<details>
<summary><b>✨ Features</b></summary>

| | |
|---|---|
| **Inventory** | Scan, locations, expiry, opened packs, favourites, CSV import/export |
| **AI** | Gemini · OpenAI · Llama — identify, OCR expiry, recipes, chat |
| **Shopping** | Smart list, anti-waste qty, recipe → list with pantry deduction, optional [Bring!](https://www.getbring.com/) |
| **Cooking** | Steps, TTS, timers, zero-waste tips |
| **Calendar** | Subscribe to expiries — ICS/WebCal feed for Google/Apple Calendar |
| **Alerts** | Expiry push without Home Assistant — [ntfy](https://ntfy.sh), JSON webhook or HA, with a test button |
| **PWA** | Offline queue, installable, multi-device |

**Integrations:** [Home Assistant](https://github.com/dadaloop82/ha-evershelf) · [MCP](mcp-server/README.md) · [Health Bridge](evershelf-health-bridge/README.md) · [Kiosk](evershelf-kiosk/README.md)

**Languages:** IT · EN · DE · FR · ES · ZH — details in [wiki → Features](https://github.com/dadaloop82/EverShelf/wiki/Features)

</details>

<details>
<summary><b>📰 What's new</b></summary>

**v1.15.3** — the tablet stops “resetting”: a warm reload skips the 3 s splash, the kiosk uses the HTTP cache again and reloads a hung page once (watchdog) instead of sitting on a dead frame  **v1.15.2** — no more white flashes on the Android tablet: the root canvas paints the app background (not white) and the kiosk WebView surface matches the splash colour  **v1.15.1** — the settings checklist empties itself: an option you answered leaves “Da decidere”, and saving a configuration (or the tab a “Novità” row lives in) removes it from both lists  **v1.15.0** — Settings becomes searchable: one field at the top finds a setting by name, description, synonym or `.env` key (114 controls + 48 server-only keys, all six languages) and jumps to it; a generated registry + guard test keep it honest  **v1.14.0** — custom product tags (#97): free-form labels on a product, pills on the inventory card and a filter bar above the list (AND across the selected tags, autocomplete from the tags in use), behind a normal `TAGS_ENABLED` setting; quick stocktake (#285): one card at a time with three big answers, batched writes behind `AUDIT_MODE_*`, a summary that says what changed; parent/child products (#284): an umbrella article with its brand variants, each keeping its own stock and barcode while the family total is one line, behind `PRODUCT_VARIANTS_ENABLED`; receipt OCR (#114): one photo becomes an editable table, and nothing enters the pantry before you confirm it, behind `RECEIPT_OCR_ENABLED`; a failed refresh no longer blanks the pantry or the shopping list, the smart-shopping cache declares its schema and Bring! stops suggesting what is already in stock  **v1.13.0** — a scanned tomato is never the pantry's jar of passata: the duplicate rules, the AI name→category vocabularies (browser **and** server, one source) and the AI-identified unit/quantity were rebuilt, a new pantry row below 20 g/ml (or 1 piece, ¼ pack) is refused instead of appearing as "finished", "Spesa stimata" is one number for app/kiosk/Home Assistant, and **the vocabulary now lives in `api/vocab/<lang>.json`** (genres, brands, plurals — no product names in the code, no regex on vocabulary)  **v1.12.0** — optional support, without a paywall and **without any advertising**: a one-off dialog shows the real running costs and offers two answers — donate with PayPal (with its own server-drawn QR code) or nothing for now, which is the default. The donation link, the amounts and the disclosed costs live in `.env`; the banner slots were built and then parked, so nothing is ever advertised. The kiosk wizard carries the same two answers  **v1.11.14** — “Product ID required” when adding an AI-identified product (issue #282) fixed: the catalog row is created on the fly and backend errors are translated  **v1.11.13** — the recipe ingredient “Usa” button is double the size (1.55 rem, 48 px) so it reads as the primary action; the bump script now busts the cache correctly  **v1.11.12** — safety verdicts are one-word badges (Va bene / Controlla / Non mangiare / Buttalo) with the advice on hover; removing an out-of-season product now sticks and the seasonal card suggests only what you actually buy or eat, with the reason; Bring! wording disappears when the internal list is active; bigger “Usa” buttons; i18n gaps closed  **v1.11.11** — scan screen redesign: hero camera, favourites/recents rail, AI glass tips on-stream, speculative barcode prefetch  **v1.11.10** — recipe archive dedup by `content_hash` (no more chat clones); many free-slot recipes per day; “Usa ingrediente” shows big needed qty and scale **X / Y**  **v1.11.9** — kiosk dedicated pairing step + token hand-off to WebView (kiosk **1.7.24**)  **v1.11.8** — pairing code visible on a paired device (Settings → Security); kiosk APK from LAN + `kiosk-latest`; kiosk **1.7.23** emerald Corporate UI redesign (wizard / settings / splash)  **v1.11.7** — empty recipe “missing from pantry” stub gone; undo of location moves is a pair  **v1.11.6** — HA shopping_mode + calendar attention=1  **v1.11.5** — shopping pack-equiv restock, conf caps, Bring hard-off when internal, HA expiry = dashboard rules  **v1.11.4** — Open Prices (free) + opt-in Web Push PWA; delete undo toast; torch `fillLightMode`; settings “da decidere” for prices/push  **v1.11.3** — shopping trip mode, ntfy digest/Telegram, zerowaste tips, guided settings nav  **v1.11.2** — extending expiry refreshes banner, dashboard and the current page; optimization backlog in `docs/OPTIMIZATION-BACKLOG.md`  **v1.11.1** — faster barcode lookup, top-3 auto-favourites, smart shopping catches depleted stock, Android CI stabilisation  **v1.11.0** — an article is stored in the singular (`Fetta biscottata integrale`) and the pantry shows the plural of what is really there (`3 Uova medie`, `500 g Pasta` stays a mass); certifications keep their capitals (`IGP`, `DOP`, `EVO`); the scan step lets you correct the identified title and brand before saving  **v1.10.1** — splash boot rail: stage icons go grey → blinking → coloured with an aura, a prominent version chip, and every article title now starts with a capital letter  **v1.10.0** — the genre leads every article title (`Yogurt Fiori di latte`); products you use often become favourites  **v1.9.1** — settings collapses into sub-sections, dashboard overview/freshness/trend panels, expiry push without Home Assistant (ntfy/webhook), cron watchdog, setup checklist  **v1.9.0** — Calendar feed (ICS/WebCal); recipe → shopping list with the pantry deducted; seasonal produce filters; third CSS layer  **v1.8.10** — Docker build stops baking in local data; CSRF header required on every POST; error reports redacted and GitHub publishing opt-in  **v1.8.9** — Localised tooltips, placeholders and aria labels; fixed PWA manifest icons  **v1.8.8** — One-time **pairing code** for the API token; startup no longer dead-ends behind the splash  

→ [CHANGELOG.md](CHANGELOG.md)

</details>

<details>
<summary><b>⚙️ Install & config</b></summary>

**Docker** (recommended): `docker compose pull && docker compose up -d`

**Unraid**: Community Applications template (contributed by **@DevlinDelFuego**) — `unraid/evershelf.xml`, with the guide in [docs/wiki/Unraid.md](docs/wiki/Unraid.md). It mounts the database **and** the folder holding `.env`, so both survive a container update.

**Manual:** clone → `cp .env.example .env` → `chmod 755 data/` → point web server at repo root.

**Minimum `.env`:**

```ini
AI_ENABLED=true
AI_PROVIDER=gemini
GEMINI_API_KEY=your_key
```

**Optional:** `API_TOKEN`, `SHOPPING_MODE=bring`, cron every 5 min on `api/cron_smart_shopping.php`

**Behind a reverse proxy:** set `TRUSTED_PROXIES` to the proxy IP(s)/CIDR so rate
limiting sees real client IPs, and `EVERSHELF_CANONICAL_HOST` in the Apache vhost
(`docker/apache-evershelf.conf`) to enable the forced-HTTPS redirect.

**First run with `API_TOKEN` set:** the web UI asks for a one-time **pairing code**.
On a device that is already paired, the live code is under **Settings → System →
Security** (and Info). It is also printed in the server log:

```bash
grep -i "pairing code" logs/evershelf_*.log | tail -1   # bare metal
docker logs evershelf 2>&1 | grep -i "pairing code" | tail -1   # Docker
```

The token is never handed to an anonymous request. On a fully trusted LAN you can
opt out with `API_BOOTSTRAP_OPEN=true` (re-exposes the token — see SECURITY.md).

→ [Wiki: Installation](https://github.com/dadaloop82/EverShelf/wiki/Installation) · [Configuration](https://github.com/dadaloop82/EverShelf/wiki/Configuration)

</details>

<details>
<summary><b>🔌 Integrations & API</b></summary>

| | Link |
|---|---|
| Home Assistant | [ha-evershelf](https://github.com/dadaloop82/ha-evershelf) |
| MCP agents | [mcp-server/README.md](mcp-server/README.md) |
| Health / Fuel Mode | [evershelf-health-bridge/README.md](evershelf-health-bridge/README.md) |
| Android kiosk + scale | [evershelf-kiosk/README.md](evershelf-kiosk/README.md) |
| Calendar feed (ICS) | [Wiki → Calendar Feed](https://github.com/dadaloop82/EverShelf/wiki/Calendar) |
| REST API | [Wiki → API](https://github.com/dadaloop82/EverShelf/wiki/API-Reference) |

</details>

<details>
<summary><b>🏗️ Architecture & security</b></summary>

`index.html` SPA · `api/index.php` · `assets/js/app.js` · SQLite in `data/`

→ [ARCHITECTURE.md](docs/ARCHITECTURE.md) · [CORPORATE-UI.md](docs/CORPORATE-UI.md) · [SECURITY.md](SECURITY.md)

</details>

<details>
<summary><b>🤝 Contributing</b></summary>

**[Roadmap](docs/ROADMAP.md)** — what is next, with a rough effort tag. ·
[Issues](https://github.com/dadaloop82/EverShelf/issues) ·
[Good first issues](https://github.com/dadaloop82/EverShelf/issues?q=is%3Aissue+is%3Aopen+label%3A%22good+first+issue%22) ·
[Discussions](https://github.com/dadaloop82/EverShelf/discussions) ·
[Project board](https://github.com/users/dadaloop82/projects/2)

You do not have to write code: translations, bug reproductions, testing a PR, docs and
UX feedback are all welcome. Pick something from the roadmap and say hello in the issue —
the project needs more than one pair of hands. Starting point:
[#290 — Contributing to EverShelf](https://github.com/dadaloop82/EverShelf/issues/290)
(pinned).

Add a language: copy `translations/en.json`, translate values, open a PR.

</details>

<details>
<summary><b>📸 Demo</b></summary>

<div align="center">

![Demo](assets/img/demo.gif)

[Live demo](https://evershelf.site/demo) — no install, full AI.

</div>

</details>

---

MIT · [Stimpfl Daniel](https://evershelf.site/) · [@dadaloop82](https://github.com/dadaloop82)

*Unrelated iOS app “EverShelf” by Joshumi Technologies — no affiliation.*
