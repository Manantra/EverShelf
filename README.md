# 🏠 EverShelf

> Self-hosted pantry manager — inventory, barcode scan, AI recipes, less waste.

<div align="center">

**[Website](https://evershelf.site/)** · **[Demo](https://evershelf.site/demo)** · **[Wiki](https://github.com/dadaloop82/EverShelf/wiki)** · **[Changelog](CHANGELOG.md)**

[![MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE) [![PHP 8+](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://www.php.net/) [![Version](https://img.shields.io/badge/version-1.16.30-brightgreen.svg)](CHANGELOG.md) [![Branch](https://img.shields.io/badge/branch-refactor__25__10__2026)](../../branches) [![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

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

**v1.16.30** — il microfono della cattura ha finalmente il **bordo animato**: un anello a gradiente che gira attorno al tasto (3 px, 3,6 s, più veloce mentre registra) con un alone che respira, così si vede a colpo d'occhio che è un pulsante; il tasto ha anche un bordo smeraldo da 2 px e un contorno di focus, e con `prefers-reduced-motion` l'anello resta fermo  **v1.16.29** — il banner «meno scorte del previsto» chiede una cosa sola e offre due risposte: **Conferma 2000 ml** (verde pieno, scrive il valore del registro) e **Modifica quantità** (neutro, apre la scheda prodotto dove digitare il numero vero o segnarlo finito/buttato); Finito, Buttato, Correggi, Ignora e 🤖 Spiega escono dalla striscia (erano azioni del prodotto, il ✕ era già l'ignora, e il testo dice già il perché); il tasto primario non va più a capo e il ✕ ora **scarta davvero** l'avviso (prima tornava al refresh successivo) — senza mai toccare le scorte  **v1.16.28** — una sola voce per tutta l'app: la cattura vocale non crea più un motore TTS tutto suo, parla con la stessa voce delle ricette (stessa voce, rate e pitch scelti in Impostazioni, stesso bridge nativo del kiosk) e **sempre in locale**, qualunque motore sia configurato per le ricette; e la voce viene scelta per la lingua del testo (quella scelta se parla quella lingua → una voce offline di quella lingua → qualsiasi voce offline), così una UI tedesca, francese, spagnola o cinese non legge più con una voce italiana (anche il test in Impostazioni → TTS ora segue la lingua dell'interfaccia); chiudere la scheda non taglia più la conferma pronunciata  **v1.16.27** — il banner anomalia mostra le azioni in linea con i loro colori (Aggiorna a 2000 ml / Finito / Buttato / Correggi / Ignora), senza il ⋮ che sembrava rotto; «Dimenticati» è finalmente lo stesso riquadro di «In scadenza» (il tema lo dipingeva ancora viola e senza padding, tagliando il testo) e cambia logica: i **5 prodotti con la scadenza più vicina** tra quelli mai usati o fermi da 21+ giorni, con la data e il conto alla rovescia in riga; il microfono della cattura respira, così si vede che si clicca; l'ascolto non si arrende al primo silenzio («Ti ascolto ancora…») e capisce «mezza mela», «300gr di fusilli», «ho finito tutti i fusilli» — la quantità dettata apre la scheda già impostata e «ho finito» chiede conferma lì; se parli, l'app **risponde a voce** («Hai usato 1 mela. Confermi?», interruttore 🔊 nella scheda) e il nuovo **«Cosa posso dire?»** elenca tutte le frasi supportate, in sei lingue  **v1.16.26** — la modale prodotto ha una sola gerarchia: sotto i dati c'è “Quanto ne hai consumato?” con uno stepper (− / +), un bottone verde pieno “Registra consumo”, uno verde chiaro “Ho consumato tutto” e le azioni di supporto (ricetta, butta, estendi scadenza) in grigio neutro — barcode e orari finiscono nel footer “Dettagli” comprimibile; le righe della home tornano a proporre il consumo (un “Usa” rapido + ⋮) e “Estendi scadenza” si sposta nella modale; il banner anomalia dice cosa fa (“Aggiorna a 2000 ml”) al posto di “Giusta” e il suo menu ha bottoni veri; la cattura vocale passa al verde smeraldo con chip piene e un anello sonar sul microfono mentre registra  **v1.16.25** — gli avvisi di sistema escono da "Salva lo spreco": l'anomalia di quantità (un errore di dati, non cibo) vive in una striscia dismissibile sopra il blocco, che ora contiene solo alimenti da consumare o buttare; il badge "Controlla" non occupa più una riga intera (sta sulla riga dei metadati, 12px) e i riquadri perdono la "gabbia": bordo grigio 1px su tutti i lati e colore solo come barra verticale da 4px a sinistra; "Estendi scadenza" diventa una pillola da 40px con icona scura e etichetta  **v1.16.24** — ogni avviso dice **dove** si trova il prodotto: "Dove: 🗄️ Dispensa · 🧊 Frigo" (l'anomalia "hai 250000 g, confermi?" non lasciava capire quale scaffale controllare; il payload ora porta tutte le locazioni, non solo la prima) e la riga compare su anomalie, revisioni, previsioni, prodotti finiti e promemoria senza scadenza  **v1.16.23** — il pannello di cattura parte già in ascolto: il tocco che lo apre è il gesto che serve al browser, il riconoscitore parte subito (sincrono, dentro il gesto) e il misuratore di livello si aggancia dopo; la riga "Tocca il microfono" diventa "Parla pure: ti ascolto"  **v1.16.22** — il tasto centrale non ha più l'etichetta (il microfono è l'etichetta), è più grande degli altri (48–64 px, sollevato di 18 px) e **respira sempre** (scala + alone da 4,2 s, fermo con `prefers-reduced-motion`); via ogni residuo del vecchio brand nella barra (le regole `.nav-gemini-*`: scintilla indaco, grigio senza AI, nascosto offline) — e il tasto resta disponibile offline perché "usa" e "aggiungi" funzionano senza server  **v1.16.21** — il pannello di cattura risponde: il microfono si riempie col livello audio in ingresso (barre che si muovono), il testo compare mentre ascolta e poi in grassetto, e prima di agire vedi "ho capito: usare · arancia · ×1"; se il prodotto è ambiguo te lo chiede invece di indovinare. Motore interno multi-lingua (6 lingue, nessuna AI esterna): verbi, numeri a parole, unità e un punteggio di somiglianza che instrada solo sopra 0,75  **v1.16.20** — rifinitura UI: "Dimenticati" non scappa più dal gruppo di tab (il caricamento asincrono riapplica la selezione), lo spreco non è più duplicato (il banner non ripete i prodotti già elencati) e i sei bottoni pastello diventano **un'azione + ⋮**; nuova palette "Fresh & Crisp Slate" (sfondo #F8FAFC, card bianche, accento 4px, un solo verde #059669), tre livelli di testo (15/13/12), un unico formato date ("tra 6 gg" / "36 gg fa") e il tasto centrale con microfono e etichetta "Ascolta"  **v1.16.19** — la locazione "Altro" è stata eliminata: i prodotti che stavano lì vengono spostati nella prima locazione (Dispensa) da una migrazione una-tantum, e al primo avvio un avviso dice quanti sono e dove sono finiti; la categoria "altro" (fallback del classificatore) resta intatta  **v1.16.18** — la dashboard diventa una schermata di triage: locazioni in una rail di pill dinamiche (con puntino rosso dove c'è da salvare), hub "Salva lo spreco" che unisce banner, scaduti e ricetta, tre liste in un unico blocco a tab con contatori, statistiche spostate in Storico (in home resta una riga), righe con un solo ⋮ invece di sei pulsantini, e il centro della barra è un pulsante di cattura con voce (Web Speech API) che instrada "ho usato un'arancia", "aggiungi il latte", "cosa cucino?" ai flussi esistenti senza mai applicare nulla senza conferma  **v1.16.17** — audit approfondito: `app_settings` non consegna più `env_overrides` (chiavi API finivano al client in DEMO) e le scritture passano da una lista bianca; i backup si verificano con `PRAGMA quick_check` prima di sostituire il precedente; cinque indici per le query calde e un `get_settings` in meno a ogni avvio; toast e dialog annunciati agli screen reader; Mealie e le preferenze ricette tradotte in fr/es; la CI ora esegue i 55 test di regressione, `shellcheck`, il lint di ogni JS e l'audit i18n reale  **v1.16.16** — il wizard di configurazione segue il server, non il browser: con un `.env` in sola lettura un nuovo dispositivo non lo riapre, lo step Google Drive sparisce con `GDRIVE_ENABLED=false` e quello della lingua con `APP_LANG` (issue #301)  **v1.16.15** — anche le liste minori smettono di troncare: risultati della ricerca rapida, candidati del match AI (non si capiva quale articolo fosse stato scelto), righe della ricevuta e etichette del grafico “Spesa per categoria”  **v1.16.14** — le liste sono leggibili sul telefono: nuovo layer CSS mobile (testo a tutta larghezza, badge e quantità sotto, niente troncamenti né titoli schiacciati a una lettera per riga)  **v1.16.13** — il dismiss vale anche per il sync in tempo reale (un cambio di stock non rimette in lista ciò che hai scartato)  **v1.16.12** — “Non lo compro” resta valido: un dismiss con scadenza (default 14 giorni, `dismiss_days` per chiamata) che il cron rispetta, così un client esterno non ritrova i prodotti in lista dopo cinque minuti; un acquisto lo cancella  **v1.16.11** — le date seguono il paese: in Italia gg/mm/aaaa (e non "2026-10-19" né il mese a parole) in banner, import e log; fr/es non ricevono più il formato italiano, e il TTS parla la lingua dell’interfaccia  **v1.16.10** — a shopping row stops contradicting itself: it reports the stock of its whole family (no more “Esaurito” next to “Hai già 235 g”), the pantry hint counts the same family only, and raw cane sugar groups with cane sugar instead of white sugar  **v1.16.9** — a tap opens the alert rows on the phone too (the 900 ms hold was the only way in, and "aperti da troppo tempo" was not clickable at all)  **v1.16.8** — the quantities finally match the window you chose (25 days, not the inferred week: 5 lemons a month are 5, not 2), perishables are planned with the shelf life of where you actually keep them, a product that expires **today** is no longer listed as expired, one or two days past the date are “Controlla” instead of “Non mangiare”, and a drink says “Non bere”  **v1.16.7** — the shopping automations stop fighting: the prune no longer deletes the row the auto-add has just created (add → prune → add every 5 minutes), and it never touches a row you marked as finished  **v1.16.6** — a finished product gets back on the shopping list: the family rule no longer hides a real shortfall by adding grams and packs together (or counting expired batches and 0.5 g smears) — “360 g” of lemon jam used to keep every jar of jam off the list — “Usa tutto” now always lands on the list, and an expired batch with fresh stock no longer adds a row with no quantity  **v1.16.5** — the app opens in the language the link asked for (`/?lang=de`, with the picker keeping the URL in step) and the vocabulary answers again: `core/vocab.js` had lost two braces, so nine engine functions — piece produce, prepared salad, processed food, singular/plural — were undefined in the browser; a 429 now carries a translatable `error_key` (six locales), the add form's units are translated with a badge that follows the select, and the shopping list's urgency prefix is recognised by its marker instead of its Italian word  **v1.16.4** — the quick stocktake speaks: each card now reads “Ci sono ancora **120 g** di **Formaggio Morbido** di **Barilla** in **Frigo**” with the quantity in a big accent pill, and the answers really act — *Sì, confermo*, *Cambio quantità* and *Cambio posizione* (the normal edit sheet opens on top of the run, manual or from the scale, without losing it), *No, è esaurito* (the real “usa tutto”) and *No, è deperito* (the real “butta tutto”, counted as waste); a run closed by mistake resumes from the point reached for an hour, when the scale is connected *Leggi dalla bilancia* fills the quantity in one tap, and *Indietro* undoes the last answer — even the last article, from the summary  **v1.16.3** — the backup panel speaks the UI language: restore/delete/Drive messages are translated from `error_key`s in all six locales, the hardcoded English and the Italian `'gg'` fallback are gone, and a guard test keeps them out  **v1.16.2** — the daily backup actually runs: the Docker image now ships its own cron (every 5 minutes), a backup is a consistent `VACUUM INTO` snapshot, and the Backup panel warns when the last one is missing or older than 8 days; backups live inside the data volume, so copy them off-volume  **v1.16.1** — a hand-edited `.env` wins again: the database fallback written when Settings cannot save the file no longer shadows it, so changing `OPENAI_MODEL` (or any key) in `.env` and restarting takes effect; the AI connection test names the model it actually called and logs the provider's message  **v1.16.0** — installable from Unraid, with the template versioned in the repo and a container-safe `.env` (`EVERSHELF_ENV_FILE`) that survives an update  **v1.15.3** — the tablet stops “resetting”: a warm reload skips the 3 s splash, the kiosk uses the HTTP cache again and reloads a hung page once (watchdog) instead of sitting on a dead frame  **v1.15.2** — no more white flashes on the Android tablet: the root canvas paints the app background (not white) and the kiosk WebView surface matches the splash colour  **v1.15.1** — the settings checklist empties itself: an option you answered leaves “Da decidere”, and saving a configuration (or the tab a “Novità” row lives in) removes it from both lists  **v1.15.0** — Settings becomes searchable: one field at the top finds a setting by name, description, synonym or `.env` key (114 controls + 48 server-only keys, all six languages) and jumps to it; a generated registry + guard test keep it honest  **v1.14.0** — custom product tags (#97): free-form labels on a product, pills on the inventory card and a filter bar above the list (AND across the selected tags, autocomplete from the tags in use), behind a normal `TAGS_ENABLED` setting; quick stocktake (#285): one card at a time with three big answers, batched writes behind `AUDIT_MODE_*`, a summary that says what changed; parent/child products (#284): an umbrella article with its brand variants, each keeping its own stock and barcode while the family total is one line, behind `PRODUCT_VARIANTS_ENABLED`; receipt OCR (#114): one photo becomes an editable table, and nothing enters the pantry before you confirm it, behind `RECEIPT_OCR_ENABLED`; a failed refresh no longer blanks the pantry or the shopping list, the smart-shopping cache declares its schema and Bring! stops suggesting what is already in stock  **v1.13.0** — a scanned tomato is never the pantry's jar of passata: the duplicate rules, the AI name→category vocabularies (browser **and** server, one source) and the AI-identified unit/quantity were rebuilt, a new pantry row below 20 g/ml (or 1 piece, ¼ pack) is refused instead of appearing as "finished", "Spesa stimata" is one number for app/kiosk/Home Assistant, and **the vocabulary now lives in `api/vocab/<lang>.json`** (genres, brands, plurals — no product names in the code, no regex on vocabulary)  **v1.12.0** — optional support, without a paywall and **without any advertising**: a one-off dialog shows the real running costs and offers two answers — donate with PayPal (with its own server-drawn QR code) or nothing for now, which is the default. The donation link, the amounts and the disclosed costs live in `.env`; the banner slots were built and then parked, so nothing is ever advertised. The kiosk wizard carries the same two answers  **v1.11.14** — “Product ID required” when adding an AI-identified product (issue #282) fixed: the catalog row is created on the fly and backend errors are translated  **v1.11.13** — the recipe ingredient “Usa” button is double the size (1.55 rem, 48 px) so it reads as the primary action; the bump script now busts the cache correctly  **v1.11.12** — safety verdicts are one-word badges (Va bene / Controlla / Non mangiare / Buttalo) with the advice on hover; removing an out-of-season product now sticks and the seasonal card suggests only what you actually buy or eat, with the reason; Bring! wording disappears when the internal list is active; bigger “Usa” buttons; i18n gaps closed  **v1.11.11** — scan screen redesign: hero camera, favourites/recents rail, AI glass tips on-stream, speculative barcode prefetch  **v1.11.10** — recipe archive dedup by `content_hash` (no more chat clones); many free-slot recipes per day; “Usa ingrediente” shows big needed qty and scale **X / Y**  **v1.11.9** — kiosk dedicated pairing step + token hand-off to WebView (kiosk **1.7.24**)  **v1.11.8** — pairing code visible on a paired device (Settings → Security); kiosk APK from LAN + `kiosk-latest`; kiosk **1.7.23** emerald Corporate UI redesign (wizard / settings / splash)  **v1.11.7** — empty recipe “missing from pantry” stub gone; undo of location moves is a pair  **v1.11.6** — HA shopping_mode + calendar attention=1  **v1.11.5** — shopping pack-equiv restock, conf caps, Bring hard-off when internal, HA expiry = dashboard rules  **v1.11.4** — Open Prices (free) + opt-in Web Push PWA; delete undo toast; torch `fillLightMode`; settings “da decidere” for prices/push  **v1.11.3** — shopping trip mode, ntfy digest/Telegram, zerowaste tips, guided settings nav  **v1.11.2** — extending expiry refreshes banner, dashboard and the current page; optimization backlog in `docs/OPTIMIZATION-BACKLOG.md`  **v1.11.1** — faster barcode lookup, top-3 auto-favourites, smart shopping catches depleted stock, Android CI stabilisation  **v1.11.0** — an article is stored in the singular (`Fetta biscottata integrale`) and the pantry shows the plural of what is really there (`3 Uova medie`, `500 g Pasta` stays a mass); certifications keep their capitals (`IGP`, `DOP`, `EVO`); the scan step lets you correct the identified title and brand before saving  **v1.10.1** — splash boot rail: stage icons go grey → blinking → coloured with an aura, a prominent version chip, and every article title now starts with a capital letter  **v1.10.0** — the genre leads every article title (`Yogurt Fiori di latte`); products you use often become favourites  **v1.9.1** — settings collapses into sub-sections, dashboard overview/freshness/trend panels, expiry push without Home Assistant (ntfy/webhook), cron watchdog, setup checklist  **v1.9.0** — Calendar feed (ICS/WebCal); recipe → shopping list with the pantry deducted; seasonal produce filters; third CSS layer  **v1.8.10** — Docker build stops baking in local data; CSRF header required on every POST; error reports redacted and GitHub publishing opt-in  **v1.8.9** — Localised tooltips, placeholders and aria labels; fixed PWA manifest icons  **v1.8.8** — One-time **pairing code** for the API token; startup no longer dead-ends behind the splash  

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
