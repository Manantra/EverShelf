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
