# User-Submitted Archetypes — API Design Spec

## Goal

Allow users to submit their own archetypes via the client app. Submissions are stored in the existing `archetypes` + `deck_versions` tables with ownership tracking. Unapproved submissions only affect the submitting user's archetype matching. Approval makes them globally available.

## Schema Changes

### `archetypes` table — two new columns

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `submitted_by` | string | yes | null | Username of submitter. Null = system/provider archetype. |
| `approved_at` | timestamp | yes | null | When approved by admin. Null = pending. |

Existing archetypes (from provider sync) keep both null — they are implicitly approved system archetypes.

### Slug uniqueness

The `archetypes` table has a `UNIQUE(format, slug)` constraint. The slug is auto-generated from `name` in the model's `booted()` hook. Two users submitting "Eldrazi Tron" in "modern" would collide. Fix: change the unique constraint to `UNIQUE(format, slug, submitted_by)` — this allows the same name across different submitters while preserving uniqueness within each scope (system vs each user).

### `X-Username` trust boundary

The `X-Username` header is unverified — any authenticated device can claim any username. This means a user could theoretically see another user's unapproved submissions or submit on their behalf. For v1 this is acceptable since MTGO usernames are not secret and the impact is limited (you'd only see extra archetypes in your matching results). A future improvement could tie submissions to `device_id` for stronger attribution.

## New Endpoints

### `POST /api/archetypes/submit`

Requires device auth + `X-Username` header.

**Request:**
```json
{
    "name": "Eldrazi Tron",
    "format": "modern",
    "cards": [
        { "mtgo_id": 12345, "quantity": 4, "zone": "main" },
        { "mtgo_id": 67890, "quantity": 2, "zone": "side" }
    ]
}
```

**Validation:**
- `name` — required, string, max 255
- `format` — required, string
- `cards` — required, array, min 1
- `cards.*.mtgo_id` — required, integer
- `cards.*.quantity` — required, integer, min 1
- `cards.*.zone` — required, enum: main|side

**Flow:**
1. Validate all `mtgo_id` values exist in `mtgo_ids` table. If any are missing, return 422 with the list of unresolvable IDs.
2. Resolve color identity using the existing `GetDeckColorIdentity::run()` action for consistency (it applies a minimum threshold of 4 cards per color before including it).
3. Find or create archetype by `name` + `format` + `submitted_by` (so resubmitting the same name/format updates rather than duplicates). Set `submitted_by` from the `X-Username` header.
4. Create a new `DeckVersion` with a signature hash of the card list. The `signature` column has a global unique constraint — if the exact same card list already exists as a deck version (for this or another archetype), skip creation and link to the existing version. Create `DeckVersionCard` rows linking to `scryfall_cards` via the `mtgo_ids` table.
5. Return the archetype.

**Implementation note:** The submission logic (card resolution, archetype creation, deck version creation) should live in an action class `app/Actions/Metagame/SubmitArchetype.php` for consistency with the existing action pattern.

**Success response (200):**
```json
{
    "uuid": "...",
    "name": "Eldrazi Tron",
    "format": "modern",
    "colorIdentity": "C"
}
```

**Validation error (422):**
```json
{
    "message": "Some cards could not be resolved.",
    "errors": {
        "cards": ["The following MTGO IDs could not be found: 99999, 88888"]
    },
    "unresolvable_ids": [99999, 88888]
}
```

### `GET /api/archetypes/mine`

Requires device auth + `X-Username` header.

Returns all archetypes where `submitted_by` matches the `X-Username` header value, regardless of approval status.

**Response:**
```json
[
    {
        "uuid": "...",
        "name": "Eldrazi Tron",
        "format": "modern",
        "colorIdentity": "C",
        "approvedAt": null
    }
]
```

Note: includes `approvedAt` so the client can show approval status.

## Modified Endpoints

### `POST /api/archetypes/estimate`

Now accepts an optional `X-Username` header.

The username must be threaded from the route through `GetArchetypeFromDeck::get()` (the entry point) into `GetArchetypeFromCardIds::get()` (where the candidate query lives). The candidate query currently selects deck versions by format only. This changes to:

```
deck_versions
WHERE archetype.format = :format
AND (
    archetype.submitted_by IS NULL          -- system archetypes (always included)
    OR archetype.approved_at IS NOT NULL    -- approved user submissions
    OR archetype.submitted_by = :username   -- this user's unapproved submissions
)
```

If `X-Username` is not provided, the query reduces to the current behaviour (system + approved only).

### `GET /api/archetypes` — add filter

Currently returns all archetypes unfiltered (`Archetype::get()`). Must add a filter so that only system archetypes (`submitted_by IS NULL`) and approved user submissions (`approved_at IS NOT NULL`) are returned. Without this, unapproved submissions would leak to all clients.

The client's weekly sync behaviour is otherwise unaffected.

## Approval Process

For v1, approval is manual — an admin sets `approved_at = now()` directly in the database. No admin UI needed yet.

When an archetype is approved:
- It appears in `GET /api/archetypes` for all clients
- It's included in matching for all users (not just the submitter)
- The weekly client sync picks it up automatically

## Files to Create/Modify

### New files
- Migration: add `submitted_by` and `approved_at` to `archetypes`, update slug unique constraint
- `app/Http/Controllers/Archetypes/SubmitController.php`
- `app/Http/Controllers/Archetypes/MineController.php`
- `app/Http/Requests/SubmitArchetypeRequest.php` (form request validation)
- `app/Actions/Metagame/SubmitArchetype.php` (submission logic action)

### Modified files
- `routes/api.php` — add new routes, add filter to `GET /api/archetypes`, pass username to estimate action
- `app/Actions/Metagame/GetArchetypeFromDeck.php` — accept and pass through username parameter
- `app/Actions/Metagame/GetArchetypeFromCardIds.php` — add username filter to candidate query
- `app/Actions/Metagame/SyncArchetypes.php` — scope to `submitted_by IS NULL` to prevent clobbering user submissions
- `app/Models/Archetype.php` — update slug generation to account for `submitted_by`
- `app/Data/ArchetypeData.php` — add `approvedAt` field for the mine endpoint
