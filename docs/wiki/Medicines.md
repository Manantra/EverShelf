# EverShelf Med — the opt-in medicine cabinet

> Status: **first slice shipped** (v1.21.0). The AIFA/AIC catalogue import is the next step;
> nothing here invents medicine data.

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
| Turn a **Pharmacode/short code** into the prefilled manual entry | Dead-end on "not found" or invent a catalogue entry |

## Where the barcode stands (Phase 1)

**No medicine catalogue is queried.** The scan of a medicine box therefore does not "recognise"
anything, and that is by design:

- the food catalogues the app already uses (Open Food Facts) **do not cover medicines**, and no
  free/open equivalent exists that is safe to use for a household cabinet. Inventing drug data
  would be worse than having none;
- drug data is **national** (AIFA in Italy, other registries elsewhere), and Italian boxes carry
  **two** codes: the EAN-13 (often `80…`, GS1 Italy) and the **SSN label**, which is a
  **Pharmacode/Laetus** (base-32 or 4–7 digits), not an EAN;
- so the app **stores whichever code it saw on the box you create** and you type the name. It
  works in every country, with no API, no key and no country gate — there is nothing to gate
  while nothing is looked up.

What the scanner does with the module on:

| Scanned | Behaviour |
|---|---|
| Pharmacode / Laetus / short code | straight to the manual entry, **medical switch already on** + the 8 chips |
| EAN-13 / EAN-8 the food catalogues miss | one question: *"È un farmaco o un integratore?"* → **[💊 Sì]** (medical form) / **[No]** (normal product) / [Annulla] (camera resumes) |
| EAN-13 the food catalogues know | normal product path (a pharmacy item that happens to be in OFF is fine) |

The UI says it in *Settings → Farmaci* (`medical.no_catalogue`) and the credits page says it
where the free APIs are thanked (`credits.medicines_note`): the missing service is part of an
honest credit list.

**Phase 2 will need the country**: when the AIFA catalogue lands (filtered to OTC/SOP/C, a
2–3 MB local file), the module has to be enabled only for the matching country — a national
dataset cannot answer for another country's boxes. That gate does not exist today because
there is no catalogue to misuse.


`medicalMatchSymptom()` reads `data/medical_categories.json` (8 household categories → i18n
label + the words a household actually says, in the six shipped locales), tries the **longest
phrase first** (“mal di testa” before “testa”), and returns the categories.
`med_symptom_search` then lists the **medical rows only** whose `products.med_tags` contain one
of those categories (falling back to the ATC prefix both ways — `N02BE01` belongs to `N02B` —
when the Phase-2 catalogue sets one). Offline, no AI, no dosage.

From **Settings → 💊 Farmaci** each product gets its category chips, its active ingredient and
its PAO number (`med_classify`), and each physical box gets **🔓 Segna aperto**
(`med_open` → expiry = today + N, `expiry_user_set = 1`, so a rescan cannot undo it).
SHELFY hands a sentence it cannot act on to the same map, so “ho un forte mal di testa” opens
the list instead of creating a product, and the scanner turns a Pharmacode/Laetus (4–7 digits
or a base-32 code) into the prefilled manual entry with *"Codice rilevato…"*.

## What is next (Phase 2)

1. **AIFA open data import** (AIC → commercial name, active ingredient, ATC) into a local
   catalogue, plus the **Pharmacode (base-32) → AIC** decode on the scanner, so a box is
   recognised instead of typed.
2. **Duplicate active-ingredient warning** (“you already have 2 boxes of ibuprofen”).
3. **Leaflet link** by AIC (the paper box is often gone).
4. Per-medicine **PAO** override (eye drops 28 days, syrup 30) on top of the generic
   `data/opened_shelf_life.json` families.

Guard test: `php scripts/test-medical.php` (42 checks: opt-in, disclaimer, domain
separation, matcher, safety copy, schema, wiring).

## Country schemas — add yours (contributors)

Which barcode is a medicine pack is a **per-country fact**, so it lives in
**`data/medical_code_schemas.json`** (data only, with the sources in the file). Each country
declares:

- `status`: `full` (national code formats **and** the GS1 pharma range **and** the EU FMD
  DataMatrix), `partial` (only the GS1 range and/or the FMD), `none` (no schema — the app then
  claims nothing and only offers manual entry);
- `gs1`: the prefix ranges owned by that country's GS1 office (Italy `800–839`, Germany
  `400–440`, France `300–379`, Spain `840–849`, UK `500–509`, US `000–139`, …);
- `codes`: the national formats — `ean13`, `pharmacode` (Italy's SSN label: an integer
  3–131070 read from bars, **not** decimal digits), `pzn` (Germany, 8 digits in Code 39),
  `pzn_in_ean` (Austria, `908888…`), `cn` (Spain), `ndc` (US), `aic` (Italy, printed);
- `fmd`: whether the EU Falsified Medicines Directive DataMatrix applies.

**Adding a country is a data change**: copy a block, set the ranges and the code types, add it
to the table below, and run `php scripts/test-medical.php` (it asserts the file parses, that
`IT`/`DE` stay complete, and that a country with no schema claims **nothing**). No code, no
new action, no migration.

| Country | Status | What we can say |
|---|---|---|
| 🇮🇹 IT | full | EAN-13 `80…`, SSN Pharmacode/Laetus, AIC printed |
| 🇩🇪 DE | full | EAN-13 `40…`, PZN (Code 39) |
| 🇦🇹 AT | partial | EAN-13 `90…`, PZN embedded (`908888…`) |
| 🇫🇷 FR | partial | EAN-13 `30…` (CIP13) |
| 🇪🇸 ES | partial | EAN-13 `84…`, Código Nacional |
| 🇨🇭 CH, 🇧🇪 BE, 🇳🇱 NL, 🇵🇹 PT, 🇵🇱 PL, 🇬🇷 GR, 🇸🇪 SE, 🇩🇰 DK, 🇫🇮 FI, 🇮🇪 IE, 🇬🇧 GB | partial | GS1 range (+ FMD where it applies) |
| 🇺🇸 US | partial | UPC/EAN `00–13`, NDC |
| anything else | none | nothing: manual entry, and the app says so |

**Still missing (the honest gap):** the **EU FMD DataMatrix** is declared (`fmd: true`) but not
yet decoded: most browser barcode engines do not expose `data_matrix`, and parsing its GS1
element string (GTIN + expiry + batch + serial) needs its own tests. That is the next step
that would let the scan prefill the **expiry date** straight from the box — and it is the
reason a modern EU box may still need the manual path today.
