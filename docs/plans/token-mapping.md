# Token Card Mapping — Problem & Next Steps

## The Problem

MTGO tokens (Eldrazi Spawn, Goblin, Soldier, etc.) appear in game replay snapshots with a `CatalogID` but can't be resolved to a name, type, or image.

**Why it fails:**
- Game state JSON only has `CatalogID` for each card (no name, no token flag)
- `CreateMissingCards` creates a stub `Card` record with `mtgo_id = CatalogID`
- `PopulateMissingCardData` sends the ID to the mymtgo API → API looks up Scryfall bulk data
- **Scryfall tokens have NO `mtgo_id` field** — lookup returns nothing
- Goatbots doesn't list tokens either (not purchasable)
- Card stays as an empty stub: no name, no image, no type

## What We Have

### MTGO XML Card Data (`CardDataSource/`)
- Per-set XML files with every card's `DigitalObjectCatalogID` (= the CatalogID from game logs)
- `client_TOK.xml` has all token definitions with `IS_TOKEN=1` and `DIGITAL_OBJECT_TYPE_CODE_STRING="TOKN"`
- Each entry has metadata: `POWER`, `TOUGHNESS`, `COLOR`, `CREATURE_TYPE_STRING0`, etc.
- **Card names are string references** like `CARDNAME_STRING id="ID259_33646"` — the string table (`CARDNAME_STRING.xml` or similar) is NOT in this directory

### Scryfall
- Tokens DO exist in Scryfall with `oracle_id`, name, type, images
- They just lack `mtgo_id` so we can't match by MTGO's CatalogID
- Searchable by name: `https://api.scryfall.com/cards/search?q=t%3Atoken+"Eldrazi+Spawn"`

### What's Missing
The **CARDNAME_STRING** string table file — maps `ID259_*` references to actual card names. This file must exist somewhere in the MTGO installation alongside the `CardDataSource` XMLs.

## The Plan

### Step 1: Find the CARDNAME_STRING file
On the Windows machine, look in the MTGO data directory (same place the `CardDataSource` XMLs came from) for:
- A file named something like `CARDNAME_STRING.xml` or similar
- It would contain entries like: `<CARDNAME_STRING_ITEM id="ID259_33646">Eldrazi Spawn</CARDNAME_STRING_ITEM>`
- Check the same directory, parent directories, or sibling directories
- The MTGO install path is typically `%LOCALAPPDATA%\Apps\2.0\...` or similar

### Step 2: Parse the XML to build a CatalogID → name mapping
Once we have the string table:
1. Parse `client_TOK.xml` (and any `_DO.xml` files that contain tokens)
2. For each `DigitalObject` with `IS_TOKEN=1`:
   - Extract `DigitalObjectCatalogID` (strip `DOC_` prefix = the CatalogID)
   - Look up `CARDNAME_STRING id` in the string table → get the token name
   - Extract `POWER`, `TOUGHNESS`, `COLOR`, creature type
3. Store this as a mapping: `{ mtgo_id: 43, name: "Eldrazi Spawn", power: 0, toughness: 1, ... }`

### Step 3: Resolve images via Scryfall
With the token name known:
- Search Scryfall API: `q=t:token+"Eldrazi Spawn"` → get image URL
- Cache the result on the Card record (same as regular cards)

### Step 4: Integration
Either:
- **API-side**: Ship the mapping to the mymtgo API so it can resolve token CatalogIDs
- **Client-side**: Parse the XMLs locally and populate Card records directly, bypassing the API for tokens

## Files Involved

| File | Role |
|------|------|
| `CardDataSource/client_TOK.xml` | Token definitions with CatalogIDs |
| `CardDataSource/REAL_ORACLETEXT_STRING.xml` | Oracle text string table (ID1569_*) |
| `CardDataSource/ALT_NAME_STRING.xml` | Alt name string table (ID1_*) |
| **MISSING: CARDNAME_STRING.xml** | Card name string table (ID259_*) |
| `app/Actions/Cards/CreateMissingCards.php` | Creates stub Card records |
| `app/Jobs/PopulateMissingCardData.php` | Enriches cards via API |
| `app/Http/Controllers/Games/ShowController.php` | Enriches timeline with card data (added `name` field) |

## Quick Reference: Token XML Structure

```xml
<!-- From client_TOK.xml -->
<DigitalObject DigitalObjectCatalogID="DOC_43">
    <CARDNAME_STRING id="ID259_33646"/>        <!-- Need string table for this -->
    <DIGITAL_OBJECT_TYPE_CODE_STRING value="TOKN"/>
    <IS_TOKEN value="1"/>
    <IS_CREATURE value="1"/>
    <POWER value="0"/>
    <TOUGHNESS value="1"/>
    <COLOR id="ID518_14"/>
    <CREATURE_TYPEID0 id="ID524_260"/>
    <CREATURE_TYPE_STRING0 id="ID780_259"/>    <!-- Also a string reference -->
</DigitalObject>
```

## Scryfall Token Example

```
GET https://api.scryfall.com/cards/search?q=t:token+"Eldrazi+Spawn"

→ name: "Eldrazi Spawn"
→ oracle_id: "3aaf906a-e749-4e86-ac79-97650b92f271"
→ type_line: "Token Creature — Eldrazi Spawn"
→ mtgo_id: null (NOT PRESENT)
→ image_uris.normal: "https://cards.scryfall.io/normal/front/..."
```
