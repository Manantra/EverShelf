# EverShelf Med — the opt-in medicine cabinet

> Status: **shipped** (v1.21.0 the cabinet, v1.28.0 the printed-AIC reader and the local AIFA
> catalogue). No medicine data is bundled with the repository: the names come from the AIFA
> open data **imported by the household**, on their own server.

EverShelf is a pantry manager. Counting boxes of paracetamol is a different job with a
different risk profile, so the module is **opt-in, off by default and self-contained**:

- `MED_ENABLED=false` in `.env`, or the switch in **Settings → Salute**.
- Turning it on **without accepting the notice** opens the notice instead of enabling
  anything. The acceptance (`med_disclaimer_accepted`) is stored **once per instance** in
  `app_settings`, so a new phone is not re-asked — and the medical UI does not open without it.

## What it does (and what it refuses to do)

| It does | It never does |
|---|---|
| Live in its **own settings section** (`Settings → 💊 Farmaci`) and ask **once** at boot | Mix medicines into recipes, shopping suggestions or `for_recipe=1` |
| Need a **dedicated location** (pick or create), painted with its own tint | Offer the recipe action on a medicine |
| Let the household pick 1..n of **8 closed categories** + active ingredient + PAO days | Let a free-text tag break the offline search |
| Keep an **expiring** box in the expiry lists with the 💊 tint | Put an expired box back among the food to "save" |
| Answer "what do I have for a headache?" from a **local** taxonomy file | Call a model to guess a therapy |
| Show what is already in the house, with the leaflet/doctor disclaimer on top | State a dose, a diagnosis or "take this one" |
| Read the **AIC printed** on the box (OCR) and name the drug from a **local** AIFA import | Claim a packaging code (Pharmacode/Laetus) is the drug's number |

## Italy: the AIC is PRINTED, and it lives in its own module

A medicine pack does **not** carry the drug's number in a barcode. What a scan reads first is
packaging:

| Read | What it is | What the app does |
|---|---|---|
| `A040313076` (Code 39) | the **AIC**, printed on the box | medicine path: the local catalogue names it |
| `040313076` (9 digits) | the AIC in the older Code 32 form | same, normalised to `A040313076` |
| `01…17…10…710…` (2D DataMatrix, FMD) | GTIN + expiry + batch + serial + **AIC in AI 710** | parses the AIs and takes the AIC, never the lot |
| `123456` (Pharmacode/Laetus, SSN label) | a **packaging** code of the line | thrown away: the label is OCR'd for the AIC |
| `16G87N` (lot/serial fragment) | the DataMatrix's batch field | thrown away: the label is OCR'd for the AIC |
| an EAN-13 | the shop barcode | the ordinary food path first; unknown → *"is it a medicine?"* |

**All of that is Italian, so all of that is a module** — never the core:

| File | Role |
|---|---|
| `api/lib/medical.php` · `assets/js/core/med.js` | **core, country-neutral**: schema loader, GS1 element-string walker, `codes`/`ignored`/`ocr` lists, the module registry, the "ask the module" hook |
| `api/lib/med/italy.php` · `assets/js/med/italy.js` | **Italy**: the three AIC forms, AI 710, the AIFA column names |
| `data/medical_code_schemas.json` | which country uses which module (`"module": "italy"`), its `codes`, `ignored`, `ocr.patterns`, `fmd_national_ai`, `dataset` |

The core asks one question — *"is this read one of YOUR drug codes?"* — and the answer arrives
from the module. A country that only needs a regex (PZN, CN, NDC) declares it in `codes` and
needs no module at all; dropping Italy leaves a scanner that still recognises the others.

The label reader is the **bundled** Tesseract (`assets/vendor/tesseract`), the same engine that
reads the digits under an EAN, in a second parameter set (letters + digits, block
segmentation). In the medicine panel it is the PRIMARY reader, not a fallback: it starts on the
first usable frames and repeats every 2 s (bounded to 45 attempts, then it says it could not
read the code), and `💊 Leggi AIC` forces one pass by hand. It reads **only the printed `A` + 9
digits** — a bare 9-digit run is the pack's serial number, printed under the SSN barcode, and is
never accepted — and it reads it **inside the rectangle** the panel draws (`#scan-med-frame`,
`_medGuideRect()`): the frame prints the expected shape (`A000000000`, from
`schema.ocr.sample`) in a monospaced face, and the particle cloud and the sweeping line of the
barcode hunt are hidden there.

**Only the printed block counts.** The Italian AIC is `A` + 9 digits: a **bare 9-digit read is
rejected** — `014058142` is the pack's progressive number (the second Code 39 of the SSN label),
and accepting it stored a serial as if it were a medicine. When a barcode like that is read the
panel says so in the status line (`medical.scan_code_ignored`) and goes back to the label.

Under the camera a one-line report says **what the reader actually saw** (`Letto «…»`, and the
crop itself when nothing matched): “it does not read” and “it read something else” are
two different problems. The reader binarizes and blows up the crop, restricts the alphabet to
`0-9A` and retries the block with a wider segmentation and a 1.6× wider band before giving
up, and the loop keeps trying (2 s, then 4 s) instead of stopping after a minute.

### A doubtful read is corrected in the form, never in a panel over the camera

`A040313076` read as `A040313072` is **one digit** — perfectly plausible to the reader,
completely wrong for the household. The camera therefore answers **one** question (“did it
read?”): a read opens the medicine form as it always did. The correction lives where the name
it would change already is — the catalogue card carries a **“Non è questo?”** row with the
**real codes one edit away** (`med_lookup?near=1` → `medicineFindNeighbours()`, ranked by
distance), each with the name, the dosage and the pack printed on its box. One tap rewrites the
code and asks the catalogue again (`_medUseCode()`).

**The form fills itself from the catalogue.** An exact AIC writes the **name, the company (titolare), the notes** (active ingredient, form, pack, ATC, class) and ticks the closed category the ATC belongs to (`_medFillFromCatalogue()`, `_medCategoryFromAtc()`). Empty fields only: what the household typed always wins, and what we wrote is remembered so switching to another neighbour code rewrites ours and nothing else.

**With no catalogue, the name is read off the box.** The catalogue is optional, but an empty form looked broken, so `_medReadLabelName()` makes one extra pass on the commercial-name block **before** the form closes the camera, and `_medPickNameLine()` keeps a single plausible line (mostly letters, 4–45 characters, None of `A.I.C.`/`TITOLARE`/`Lotto`/`Scadenza`). It lands in an editable field with a notice saying where it came from — a guess never reaches the database silently. When the catalogue is empty the card says so (`medical.match_needs_catalog`) and points at the import, instead of staying silent.

Why not a card on the scanner: a white panel glued over the viewfinder hides the label the
household is aiming at, and asking two questions at once is how a scan becomes a chore. The
scanner keeps a single line of text (below), the form does the choosing.

The guide frame fills in with the **digits already recognised** (`_medPreviewSample()`,
`A0403130···`, emerald when complete), so “it is reading” and “it is stuck” never look the same.
When it *is* stuck, the reader says why (`?debug=1`, `_medOcrGeometryNote()`): the frame size and
the exact crop rectangle — a 40 px tall crop, or a portrait stream, is a geometry answer. With no
OCR engine at all the line says so (`medical.ocr_engine_error`) instead of staying empty.

Every few seconds one pass reads the **whole frame** as sparse text (`psm 11`), which is what
rescues a box that is not inside the rectangle at all.

**Italy decodes no barcode there at all** (`scan.barcodes: false`): the native detector, ZBar,
Quagga and the digit OCR are skipped, so the only input is the printed block inside the
rectangle. **A barcode is never the drug number in that panel.** The SSN label carries several Code 39s —
the AIC on top and the pack's progressive number at the bottom — and they are indistinguishable
by shape (`A014058142` is a serial, not a medicine). Only the EU FMD DataMatrix is accepted,
because AI 710 is the national number by standard; any other read sends the scanner to the
printed block (OCR). It never invents a result: no drug code in the text, no medicine.

### Two scan panels, never mixed

`#page-scan` has two modes, chosen by the **place the pantry is showing**:

| Where you are | Panel | What it has |
|---|---|---|
| the medicine cabinet | **Scansiona farmaci** (`is-medical-scan`) | camera, 💊 Leggi AIC, Aggiungi a mano |
| any food place (`Dispensa`, frigo, freezer, custom) | **Scansiona** (food) | AI vision, name search, manual entry, continuous shopping |

`_scanModeForLocation()` reads the place, `_setScanMode()` paints the mode (title, hint, which
actions exist), and each recognition path asks `_scanModeIsMedical()` first. A drug code read in
the food panel gets an inline notice (*Apri 💊 farmaci* → the pantry on the cabinet and the
medicine panel in one tap); an EAN read in the medicine panel is refused with
`medical.scan_not_medicine`. The old "is it a medicine?" dialog no longer exists: the panel you
are in already answers that.

## The catalogue: national open data, imported once

There is **no free, unlimited, key-less REST API** for Italian medicines (AIFA publishes open
data, not a service). So the app never calls one: it reads a local table.

```bash
# CSV or XML, headers recognised by NAME (CODICE_AIC, DENOMINAZIONE, PRINCIPIO_ATTIVO, …)
php scripts/import-medicines.php aifa_farmaci.csv                     # import / refresh (IT)
php scripts/import-medicines.php aifa_farmaci.xml --truncate          # full refresh
php scripts/import-medicines.php file.csv --dry-run                   # check the header map
php scripts/import-medicines.php file.csv --country=IT                # explicit, same thing
```

- One importer, one module per country: the **column names** of a national export live in the
  country module (`medicalItalyColumnAliases()`), the store (`api/lib/medicines.php`) only knows
  how to read a table. A country without a module is refused instead of guessed.
- Where to get the Italian file: AIFA open data (<https://www.aifa.gov.it/>) or the national
  catalogue (<https://www.dati.gov.it/>, search "AIFA farmaci"). No registration.
- `medicines` (SQLite, created by `initializeDB`) holds **identification only**: AIC, commercial
  name, active ingredient, form, pack, company, ATC, fascia.
- The AIC is the primary key, so re-running the importer updates instead of duplicating.
- **Settings → 💊 Farmaci** shows how many medicines are loaded and when. "Not loaded yet" is
  said out loud: an unknown AIC then means *no catalogue*, not *no such drug*.
- `GET med_lookup?aic=…` / `?q=…` answers from that table (authenticated, read-only, no
  network). The medicine form shows the match — name, ingredient, form, pack, company — with a
  **"Usa questo nome"** button: the household confirms, nothing is auto-filled.

### Loading it from the browser

Settings → Farmaci carries **Importa il file del catalogo** (`POST med_catalog_import`):
the same import as `scripts/import-medicines.php`, without SSH, for the households that do
not have a shell. Multipart, capped at 96 MB, imported into the local table and deleted;
the answer carries the count and the block repaints. The columns come from the country
module, so the same button loads a PZN list or an NDC export. **No medicine API is called,
ever** — the free national dataset is a file, and it is read here.

The card that names a box carries its **source** (`Fonte: AIFA open data · N farmaci`) next
to the name, with the active ingredient, form, pack, company, ATC and class: an
identification, never a therapy suggestion.

## The credit for the source follows the module

AIFA is a **conditional** source in `data/free_apis.json`:

```json
{ "id": "aifa", "mark": "AIFA", "licence": "CC-BY · open data",
  "splash": true, "when": { "medical": true, "country": ["IT"] } }
```

So its mark appears on the **splash** and in the **thanks page** only on an instance whose
medicine module is on *and* whose country module is Italy — a credit for a catalogue the app
does not use would be advertising. `_freeApiActive()` does the filtering on all four surfaces,
`_syncCreditSources()` repaints them when `med_status` arrives (and when the module is switched
off), and the last active set is kept in `evershelf_credits_seen_v1` so the splash — painted
before there is a token — is right even offline. Another country ships its own entry with its
own `when`, no code change.

The official AIFA mark is not vendored in this repository: the entry draws its monogram
(`mark: "AIFA"`), and dropping the project's own asset at `assets/img/apis/aifa.svg` plus a
`"logo"` field makes the real mark appear everywhere (`logo_note` covers the attribution).

`medicalMatchSymptom()` reads `data/medical_categories.json` (8 household categories → i18n

label + the words a household actually says, in the six shipped locales), tries the **longest
phrase first** (“mal di testa” before “testa”), and returns the categories.
`med_symptom_search` then lists the **medical rows only** whose `products.med_tags` contain one
of those categories (falling back to the ATC prefix both ways — `N02BE01` belongs to `N02B` —
when the imported catalogue sets one). Offline, no AI, no dosage.

From **Settings → 💊 Farmaci** each product gets its category chips, its active ingredient and
its PAO number (`med_classify`), and each physical box gets **🔓 Segna aperto**
(`med_open` → expiry = today + N, `expiry_user_set = 1`, so a rescan cannot undo it).
SHELFY hands a sentence it cannot act on to the same map, so “ho un forte mal di testa” opens
the list instead of creating a product, and the scanner turns the printed AIC into the
prefilled medical entry with *"Codice rilevato…"*.

## What is next

1. **Duplicate active-ingredient warning** (“you already have 2 boxes of ibuprofen”), now that
   the catalogue brings `principle_attivo` with the name.
2. **Leaflet link** by AIC (the paper box is often gone).
3. Per-medicine **PAO** override (eye drops 28 days, syrup 30) on top of the generic
   `data/opened_shelf_life.json` families.
4. **More countries**: the schema already carries `codes`, `dataset` and `ignored` per country;
   a national open-data importer is the missing piece (AIFA is the one implemented).

Guard test: `php scripts/test-medical.php` (167 checks: opt-in, disclaimer, domain separation,
matcher, safety copy, the AIC/FMD/ignored rules in both ports, the catalogue importer, the
label OCR, and the copy in six locales).

## Country schemas — add yours (contributors)

Which barcode is a medicine pack is a **per-country fact**, so it lives in
**`data/medical_code_schemas.json`** (data only, with the sources in the file). Each country
declares:

- `status`: `full` (national code formats **and** the GS1 pharma range **and** the EU FMD
  DataMatrix), `partial` (only the GS1 range and/or the FMD), `none` (no schema — the app then
  claims nothing and only offers manual entry);
- `gs1`: the prefix ranges owned by that country's GS1 office (Italy `800–839`, Germany
  `400–440`, France `300–379`, Spain `840–849`, UK `500–509`, US `000–139`, …);
- `module` (optional): the country code module (`med/<slug>.php` + `assets/js/med/<slug>.js`) for
  a country whose number cannot be expressed as one regex. Italy declares `italy`; Germany does
  not need one (its PZN is a plain pattern);
- `codes`: the formats that IDENTIFY the drug — `aic` (Italy, **printed**: `A` + 9 digits, or
  the bare 9 of Code 32), `pzn` (Germany, 8 digits in Code 39), `pzn_in_ean` (Austria,
  `908888…`), `cn` (Spain), `ndc` (US), `ean13` **only where the national code really is an
  EAN** (France's CIP13, for instance);
- `ignored`: the PACKAGING codes that must be thrown away, each with `action: "ocr"` — Italy's
  `pharmacode`/`laetus` label and any short alphanumeric (the lot/serial of a DataMatrix);
- `ocr`: the `patterns` to look for on the printed label (`A[ .:-]?\d{9}`, `\d{9}`) and their
  `label_key`;
- `fmd` + `fmd_national_ai`: whether the EU Falsified Medicines Directive DataMatrix applies,
  and which AI carries the national number (`710` in Italy);
- `dataset`: the catalogue source (`id`, `label_key`, `url`, `importer`, `licence`) — the
  importer is a script, because the app never calls a medicine API.

**Adding a country is a data change**: copy a block, set the ranges and the code types, add it
to the table below, and run `php scripts/test-medical.php` (it asserts the file parses, that
`IT`/`DE` stay complete, that Italy claims only the AIC, and that a country with no schema
claims **nothing**). No new action, no migration.

**Unless your country needs logic instead of a pattern** (Italy: the AIC in three printed
forms plus AI 710). Then add `"module": "<slug>"` to the block and two small files that the
core calls:

```php
// api/lib/med/<slug>.php
function medical<Slug>Module(): array {
    return [
        'cc' => 'IT',
        'local_code' => 'medical<Slug>LocalCode',      // fn(string $read): ?array{code,type,label_key,aic?}
        'dataset_columns' => 'medical<Slug>ColumnAliases', // the national export's headers
        'dataset_id' => '<id>',
    ];
}
```

```js
// assets/js/med/<slug>.js  (loaded in index.html + sw.js, before app.js)
_medRegisterCountryModule('<slug>', {
    cc: 'IT',
    localCode(read) { /* the national rule; return {code,type,label_key,aic} or null */ },
});
```

The core never learns what an AIC is: it asks the module. The full per-country picture (what is
supported today, what to put in each field, and the traps) is
[`Medicines-Country-Schemas.md`](Medicines-Country-Schemas.md); a country with no schema is
**blocked** by a dialog that points there. Deleting `italy.php` / `italy.js` and
the `"module"` line leaves a scanner that still reads the other countries' patterns.

| Country | Status | What we can say |
|---|---|---|
| 🇮🇹 IT | full | AIC printed (`A` + 9 digits / 9 digits) and AI 710 of the FMD DataMatrix; Pharmacode/Laetus is packaging → OCR |
| 🇩🇪 DE | full | EAN-13 `40…`, PZN (Code 39) |
| 🇦🇹 AT | partial | EAN-13 `90…`, PZN embedded (`908888…`) |
| 🇫🇷 FR | partial | EAN-13 `30…` (CIP13) |
| 🇪🇸 ES | partial | EAN-13 `84…`, Código Nacional |
| 🇨🇭 CH, 🇧🇪 BE, 🇳🇱 NL, 🇵🇹 PT, 🇵🇱 PL, 🇬🇷 GR, 🇸🇪 SE, 🇩🇰 DK, 🇫🇮 FI, 🇮🇪 IE, 🇬🇧 GB | partial | GS1 range (+ FMD where it applies) |
| 🇺🇸 US | partial | UPC/EAN `00–13`, NDC |
| anything else | none | nothing: manual entry, and the app says so |

**The FMD DataMatrix is decoded**: `medicalGs1Parse()` / `_medGs1Parse()` (the CORE, GS1 standard)
walk the element string (fixed-length AIs, variable ones ending at FNC1) and return GTIN, expiry,
batch, serial and AI `71x`; the country module turns that into its own number (Italy: the AIC). The **scanner engine** is the limit: most browsers do not expose
`data_matrix` in `BarcodeDetector`, so a modern EU box still falls back to the printed AIC and
the OCR — which is exactly what the Italian schema is built around.
