# Manual Tournament Link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a right-click context menu action on matches that opens a modal to manually link/relink/unlink a match to a participated tournament, so users can correct matches that the auto-link missed or got wrong.

**Architecture:** Backend adds one action (`ManuallyLinkMatchToTournament`), one DTO (`TournamentCandidateData`), two thin single-action controllers (`LinkToTournamentController`, `CandidatesController`), and three routes. Frontend adds one dialog component wired into the existing `MatchesTable` context menu. Tournament filtering uses `TournamentType::fromPlayFormatCd($match->format) === $tournament->type` + a ±12h window around the match's `started_at`.

**Tech Stack:** Laravel 12 / PHP 8.4, Spatie Laravel Data, Pest v4, Inertia v2, Vue 3 `<script setup>`, Wayfinder, shadcn-vue.

**Spec:** `docs/superpowers/specs/2026-04-20-manual-tournament-link-design.md`

---

## File Structure

**Backend (create):**
- `app/Actions/Tournaments/ManuallyLinkMatchToTournament.php` — link + unlink logic
- `app/Data/Front/TournamentCandidateData.php` — DTO for combobox rows
- `app/Http/Controllers/Matches/LinkToTournamentController.php` — POST + DELETE handler (single-action)
- `app/Http/Controllers/Tournaments/CandidatesController.php` — GET candidates (single-action)

**Backend (modify):**
- `app/Data/Front/MatchData.php` — add `tournament` + `tournamentRound` fields
- `routes/web.php` — add 3 routes
- `app/Http/Controllers/Matches/ShowController.php` and wherever else MatchData is returned — eager-load `tournament:id,event_id,format` so the new lazy field resolves (only where relevant; see Task 1 Step 6)

**Backend tests (create):**
- `tests/Feature/Actions/Tournaments/ManuallyLinkMatchToTournamentTest.php`
- `tests/Feature/Http/Matches/LinkToTournamentControllerTest.php`
- `tests/Feature/Http/Tournaments/CandidatesControllerTest.php`

**Frontend (create):**
- `resources/js/components/matches/LinkTournamentDialog.vue`

**Frontend (modify):**
- `resources/js/components/matches/MatchesTable.vue` — new dialog ref + context menu item

---

## Task 1: Extend MatchData DTO

**Files:**
- Modify: `app/Data/Front/MatchData.php`
- Test: `tests/Feature/Data/MatchDataTest.php` (create if not already present)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Data/MatchDataTest.php`:

```php
<?php

use App\Data\Front\MatchData;
use App\Enums\MatchState;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes tournament info when the match is linked', function () {
    $tournament = Tournament::factory()->create([
        'event_id' => 12345678,
        'type' => TournamentType::Constructed,
        'format' => 'Modern',
        'participated' => true,
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 4,
    ]);

    $match->load('tournament');

    $data = MatchData::from($match)->include('tournament', 'tournamentRound')->toArray();

    expect($data['tournament'])->toMatchArray([
        'id' => $tournament->id,
        'eventId' => 12345678,
        'format' => 'Modern',
    ]);
    expect($data['tournamentRound'])->toBe(4);
});

it('omits tournament info when not linked', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
    ]);

    $match->load('tournament');

    $data = MatchData::from($match)->include('tournament', 'tournamentRound')->toArray();

    expect($data['tournament'])->toBeNull();
    expect($data['tournamentRound'])->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --compact --filter=MatchDataTest
```
Expected: FAIL — `tournament` / `tournamentRound` not in MatchData.

- [ ] **Step 3: Write minimal implementation**

Modify `app/Data/Front/MatchData.php`. Add two fields to the constructor (after `leagueName`, before `games`) and populate them in `fromModel`:

```php
public Lazy|array|null $tournament,
public Lazy|int|null $tournamentRound,
```

And in `fromModel` after the `leagueName:` line, before `games:`:

```php
tournament: Lazy::whenLoaded('tournament', $match, fn () => $match->tournament ? [
    'id' => $match->tournament->id,
    'eventId' => $match->tournament->event_id,
    'format' => $match->tournament->format,
] : null),
tournamentRound: Lazy::create(fn () => $match->tournament_round),
```

- [ ] **Step 4: Run tests**

```
php artisan test --compact --filter=MatchDataTest
```
Expected: PASS (both cases).

- [ ] **Step 5: Regenerate TypeScript transformer output**

```
php artisan typescript:transform
```
(If the project does it on demand; check `composer run dev` or similar. Skip if the dev server handles it automatically.)

- [ ] **Step 6: Eager-load tournament where MatchData is returned to the frontend**

Grep for `MatchData::from` / `MatchData::collect` to find the callers:

```
grep -rn "MatchData::" app/Http/Controllers app/Data
```

For every call that passes a loaded `MtgoMatch`, ensure `->load('tournament')` or `->with('tournament')` is in the query. For matches-list endpoints, prefer eager-loading via `->with('tournament:id,event_id,format')` on the query builder.

- [ ] **Step 7: Run Pint**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```
git add app/Data/Front/MatchData.php tests/Feature/Data/MatchDataTest.php app/Http/Controllers
git commit -m "feat: expose tournament link on MatchData DTO"
```

---

## Task 2: Create TournamentCandidateData DTO

**Files:**
- Create: `app/Data/Front/TournamentCandidateData.php`

This is a plain data carrier — no tests required by itself; it'll be exercised by Task 4's controller tests.

- [ ] **Step 1: Create the DTO**

```php
<?php

namespace App\Data\Front;

use App\Models\Tournament;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** @typescript */
class TournamentCandidateData extends Data
{
    public function __construct(
        public int $id,
        public ?int $eventId,
        public ?string $type,
        public ?string $format,
        public ?Carbon $scheduledAt,
        public ?Carbon $startedAt,
        public ?int $maxRounds,
    ) {}

    public static function fromModel(Tournament $tournament): self
    {
        return new self(
            id: $tournament->id,
            eventId: $tournament->event_id,
            type: $tournament->type?->value,
            format: $tournament->format,
            scheduledAt: $tournament->scheduled_at,
            startedAt: $tournament->started_at,
            maxRounds: $tournament->max_rounds,
        );
    }
}
```

- [ ] **Step 2: Run Pint**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```
git add app/Data/Front/TournamentCandidateData.php
git commit -m "feat: TournamentCandidateData DTO for candidate picker"
```

---

## Task 3: Create ManuallyLinkMatchToTournament action

**Files:**
- Create: `app/Actions/Tournaments/ManuallyLinkMatchToTournament.php`
- Test: `tests/Feature/Actions/Tournaments/ManuallyLinkMatchToTournamentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Tournaments\BackfillTournamentPlayerLoginIds;
use App\Actions\Tournaments\ManuallyLinkMatchToTournament;
use App\Enums\MatchState;
use App\Enums\TournamentType;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMatchWithPlayers(?int $localLoginId, ?int $opponentLoginId): MtgoMatch
{
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);
    $game = Game::factory()->create(['match_id' => $match->id]);

    $local = Player::factory()->create(['login_id' => $localLoginId]);
    $opponent = Player::factory()->create(['login_id' => $opponentLoginId]);

    $game->players()->attach($local->id, ['is_local' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false]);

    return $match;
}

it('links a match to a tournament and writes round + login ids', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'type' => TournamentType::Constructed]);
    $match = makeMatchWithPlayers(964394, 2714690);

    ManuallyLinkMatchToTournament::link($match, $tournament, 3);

    $match->refresh();
    expect($match->tournament_id)->toBe($tournament->id);
    expect($match->tournament_round)->toBe(3);
    expect($match->participant_login_ids)->toEqualCanonicalizing([964394, 2714690]);
});

it('overwrites a previous link when relinking', function () {
    $old = Tournament::factory()->create(['participated' => true]);
    $new = Tournament::factory()->create(['participated' => true]);
    $match = makeMatchWithPlayers(1, 2);
    $match->update(['tournament_id' => $old->id, 'tournament_round' => 5]);

    ManuallyLinkMatchToTournament::link($match, $new, 2);

    $match->refresh();
    expect($match->tournament_id)->toBe($new->id);
    expect($match->tournament_round)->toBe(2);
});

it('writes empty login id array when players have no login_id', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = makeMatchWithPlayers(null, null);

    ManuallyLinkMatchToTournament::link($match, $tournament, 1);

    $match->refresh();
    expect($match->participant_login_ids)->toBe([]);
});

it('unlinks a match', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = makeMatchWithPlayers(1, 2);
    $match->update([
        'tournament_id' => $tournament->id,
        'tournament_round' => 4,
        'participant_login_ids' => [1, 2],
    ]);

    ManuallyLinkMatchToTournament::unlink($match);

    $match->refresh();
    expect($match->tournament_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
    expect($match->participant_login_ids)->toBeNull();

    expect(Tournament::find($tournament->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --compact --filter=ManuallyLinkMatchToTournamentTest
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the action**

```php
<?php

namespace App\Actions\Tournaments;

use App\Models\MtgoMatch;
use App\Models\Tournament;

class ManuallyLinkMatchToTournament
{
    /**
     * Link (or relink) a match to a tournament.
     *
     * Writes tournament_id, tournament_round, and participant_login_ids
     * (derived from the match's own players' login_id values, nulls filtered).
     * Dispatches the login-id backfill so standings can resolve usernames.
     */
    public static function link(MtgoMatch $match, Tournament $tournament, int $round): void
    {
        $loginIds = $match->games()
            ->join('game_player', 'game_player.game_id', '=', 'games.id')
            ->join('players', 'players.id', '=', 'game_player.player_id')
            ->whereNotNull('players.login_id')
            ->pluck('players.login_id')
            ->unique()
            ->values()
            ->all();

        $match->update([
            'tournament_id' => $tournament->id,
            'tournament_round' => $round,
            'participant_login_ids' => array_map('intval', $loginIds),
        ]);

        BackfillTournamentPlayerLoginIds::run($match->fresh());
    }

    public static function unlink(MtgoMatch $match): void
    {
        $match->update([
            'tournament_id' => null,
            'tournament_round' => null,
            'participant_login_ids' => null,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
php artisan test --compact --filter=ManuallyLinkMatchToTournamentTest
```
Expected: PASS (four cases).

- [ ] **Step 5: Run Pint**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```
git add app/Actions/Tournaments/ManuallyLinkMatchToTournament.php tests/Feature/Actions/Tournaments/ManuallyLinkMatchToTournamentTest.php
git commit -m "feat: ManuallyLinkMatchToTournament action for manual link/unlink"
```

---

## Task 4: Create CandidatesController

**Files:**
- Create: `app/Http/Controllers/Tournaments/CandidatesController.php`
- Test: `tests/Feature/Http/Tournaments/CandidatesControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MatchState;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns participated tournaments matching the match type within ±12h', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'CMODERN',
        'started_at' => now(),
    ]);

    $nearby = Tournament::factory()->create([
        'type' => TournamentType::Constructed,
        'participated' => true,
        'started_at' => now()->subHours(2),
    ]);
    Tournament::factory()->create([
        'type' => TournamentType::Constructed,
        'participated' => true,
        'started_at' => now()->subDays(3),
    ]);
    Tournament::factory()->create([
        'type' => TournamentType::Limited,
        'participated' => true,
        'started_at' => now(),
    ]);
    Tournament::factory()->create([
        'type' => TournamentType::Constructed,
        'participated' => false,
        'started_at' => now(),
    ]);

    $response = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id]));

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['id' => $nearby->id]);
});

it('returns all participated tournaments when all=1', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'CMODERN',
        'started_at' => now(),
    ]);

    Tournament::factory()->count(3)->create(['participated' => true]);
    Tournament::factory()->create(['participated' => false]);

    $response = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id, 'all' => 1]));

    $response->assertOk();
    $response->assertJsonCount(3);
});

it('skips the time filter when the match has no started_at', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'CMODERN',
        'started_at' => null,
    ]);

    Tournament::factory()->count(2)->create([
        'type' => TournamentType::Constructed,
        'participated' => true,
        'started_at' => now()->subWeek(),
    ]);

    $response = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id]));

    $response->assertOk();
    $response->assertJsonCount(2);
});

it('returns an empty default list when the match format has no tournament type mapping', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'format' => 'Xunknown',
        'started_at' => now(),
    ]);

    Tournament::factory()->count(2)->create(['participated' => true, 'started_at' => now()]);

    $defaultResponse = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id]));
    $defaultResponse->assertOk();
    $defaultResponse->assertJsonCount(0);

    $allResponse = $this->getJson(route('tournaments.candidates', ['match_id' => $match->id, 'all' => 1]));
    $allResponse->assertOk();
    $allResponse->assertJsonCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --compact --filter=CandidatesControllerTest
```
Expected: FAIL — route not registered.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Tournaments;

use App\Data\Front\TournamentCandidateData;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Http\Request;

class CandidatesController
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'match_id' => ['required', 'integer', 'exists:matches,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        $match = MtgoMatch::findOrFail($request->integer('match_id'));
        $all = $request->boolean('all');

        $query = Tournament::query()->participated();

        if (! $all) {
            $type = TournamentType::fromPlayFormatCd($match->format);

            if (! $type) {
                return response()->json([]);
            }

            $query->where('type', $type);

            if ($match->started_at) {
                $query->whereBetween('started_at', [
                    $match->started_at->copy()->subHours(12),
                    $match->started_at->copy()->addHours(12),
                ]);
            }
        }

        $tournaments = $query->orderByDesc('scheduled_at')
            ->orderByDesc('started_at')
            ->get();

        return response()->json(TournamentCandidateData::collect($tournaments));
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, inside the `prefix => 'tournaments'` group, BEFORE `{tournament}` (otherwise `candidates` would be consumed as a tournament ID):

```php
$group->get('candidates', App\Http\Controllers\Tournaments\CandidatesController::class)->name('tournaments.candidates');
```

- [ ] **Step 5: Run test to verify it passes**

```
php artisan test --compact --filter=CandidatesControllerTest
```
Expected: PASS (four cases).

- [ ] **Step 6: Run Pint**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Tournaments/CandidatesController.php routes/web.php tests/Feature/Http/Tournaments/CandidatesControllerTest.php
git commit -m "feat: tournaments/candidates endpoint for manual link picker"
```

---

## Task 5: Create LinkToTournamentController

**Files:**
- Create: `app/Http/Controllers/Matches/LinkToTournamentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Http/Matches/LinkToTournamentControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a match to a tournament', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'max_rounds' => 8]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 3,
    ]);

    $response->assertRedirect();

    $match->refresh();
    expect($match->tournament_id)->toBe($tournament->id);
    expect($match->tournament_round)->toBe(3);
});

it('rejects an unparticipated tournament', function () {
    $tournament = Tournament::factory()->create(['participated' => false]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 1,
    ]);

    $response->assertSessionHasErrors('tournament_id');
});

it('rejects a round greater than max_rounds', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'max_rounds' => 5]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 6,
    ]);

    $response->assertSessionHasErrors('round');
});

it('allows any positive round when tournament has no max_rounds', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'max_rounds' => null]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 42,
    ]);

    $response->assertRedirect();
    expect($match->fresh()->tournament_round)->toBe(42);
});

it('rejects round of zero or negative', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 0,
    ]);

    $response->assertSessionHasErrors('round');
});

it('unlinks a match', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 4,
        'participant_login_ids' => [1, 2],
    ]);

    $response = $this->delete(route('matches.unlink-tournament', ['match' => $match->id]));

    $response->assertRedirect();
    $match->refresh();
    expect($match->tournament_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
    expect($match->participant_login_ids)->toBeNull();
});

it('returns 404 for unknown match on link', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);

    $response = $this->post(route('matches.link-tournament', ['match' => 99999]), [
        'tournament_id' => $tournament->id,
        'round' => 1,
    ]);

    $response->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

```
php artisan test --compact --filter=LinkToTournamentControllerTest
```
Expected: FAIL — route not registered.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Matches;

use App\Actions\Tournaments\ManuallyLinkMatchToTournament;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LinkToTournamentController
{
    public function store(MtgoMatch $match, Request $request)
    {
        $validated = $request->validate([
            'tournament_id' => [
                'required',
                'integer',
                Rule::exists('tournaments', 'id')->where('participated', true),
            ],
            'round' => ['required', 'integer', 'min:1'],
        ]);

        $tournament = Tournament::findOrFail($validated['tournament_id']);

        if ($tournament->max_rounds !== null && $validated['round'] > $tournament->max_rounds) {
            return back()->withErrors(['round' => 'Round must not exceed the tournament\'s max rounds.']);
        }

        ManuallyLinkMatchToTournament::link($match, $tournament, $validated['round']);

        return back();
    }

    public function destroy(MtgoMatch $match)
    {
        ManuallyLinkMatchToTournament::unlink($match);

        return back();
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, inside the `prefix => 'matches'` group, add:

```php
$group->post('{match}/tournament', [App\Http\Controllers\Matches\LinkToTournamentController::class, 'store'])->name('matches.link-tournament');
$group->delete('{match}/tournament', [App\Http\Controllers\Matches\LinkToTournamentController::class, 'destroy'])->name('matches.unlink-tournament');
```

Note: existing matches routes use `{id}` string binding. For this controller we want model binding on `{match}`, which resolves to `MtgoMatch` automatically because of the type hint.

- [ ] **Step 5: Run test to verify it passes**

```
php artisan test --compact --filter=LinkToTournamentControllerTest
```
Expected: PASS (seven cases).

- [ ] **Step 6: Run Pint**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Matches/LinkToTournamentController.php routes/web.php tests/Feature/Http/Matches/LinkToTournamentControllerTest.php
git commit -m "feat: link/unlink match to tournament endpoints"
```

---

## Task 6: Regenerate Wayfinder + TypeScript types

**Files:**
- Modify: `resources/js/actions/**`, `resources/js/routes/**` (generated)

- [ ] **Step 1: Run generators**

```
php artisan wayfinder:generate
php artisan typescript:transform
```

(If the dev server handles this automatically, start it with `composer run dev` and verify the generated files appear. Otherwise run manually.)

- [ ] **Step 2: Verify generated files exist**

```
ls resources/js/actions/App/Http/Controllers/Matches/LinkToTournamentController.ts
ls resources/js/actions/App/Http/Controllers/Tournaments/CandidatesController.ts
```

Expected: both files exist.

- [ ] **Step 3: Commit**

```
git add resources/js/actions resources/js/routes resources/js/types
git commit -m "chore: regenerate wayfinder + typescript types"
```

---

## Task 7: Create LinkTournamentDialog component

**Files:**
- Create: `resources/js/components/matches/LinkTournamentDialog.vue`

This follows the existing imperative-ref pattern used by `SetArchetypeDialog.vue`.

- [ ] **Step 1: Create the component**

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import LinkToTournamentController from '@/actions/App/Http/Controllers/Matches/LinkToTournamentController';
import CandidatesController from '@/actions/App/Http/Controllers/Tournaments/CandidatesController';

type Candidate = App.Data.Front.TournamentCandidateData;

const open = ref(false);
const matchId = ref<number | null>(null);
const currentTournamentId = ref<number | null>(null);
const candidates = ref<Candidate[]>([]);
const loading = ref(false);
const search = ref('');
const showAll = ref(false);

const selectedTournamentId = ref<number | null>(null);
const round = ref<number | null>(null);

const selectedTournament = computed(
    () => candidates.value.find((c) => c.id === selectedTournamentId.value) ?? null,
);

const isLinked = computed(() => currentTournamentId.value !== null);

const filteredCandidates = computed(() => {
    if (!search.value) return candidates.value;
    const q = search.value.toLowerCase();
    return candidates.value.filter((c) => {
        const haystack = [c.eventId?.toString(), c.format, c.type, c.startedAt, c.scheduledAt]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return haystack.includes(q);
    });
});

async function loadCandidates() {
    if (matchId.value === null) return;

    loading.value = true;
    try {
        const response = await axios.get(
            CandidatesController.url({ query: { match_id: matchId.value, all: showAll.value ? 1 : 0 } }),
        );
        candidates.value = response.data;
    } finally {
        loading.value = false;
    }
}

async function openForMatch(
    id: number,
    tournamentId: number | null,
    currentRound: number | null,
) {
    matchId.value = id;
    currentTournamentId.value = tournamentId;
    selectedTournamentId.value = tournamentId;
    round.value = currentRound;
    search.value = '';
    showAll.value = false;
    candidates.value = [];
    open.value = true;

    await loadCandidates();

    // If the current tournament isn't in the default list, fall back to all so pre-selection stays visible.
    if (tournamentId !== null && !candidates.value.some((c) => c.id === tournamentId)) {
        showAll.value = true;
        await loadCandidates();
    }
}

async function onToggleShowAll(value: boolean) {
    showAll.value = value;
    await loadCandidates();
}

const form = useForm<{ tournament_id: number | null; round: number | null }>({
    tournament_id: null,
    round: null,
});

function save() {
    if (matchId.value === null || selectedTournamentId.value === null || !round.value) return;

    form.tournament_id = selectedTournamentId.value;
    form.round = round.value;
    form.submit(LinkToTournamentController.store({ match: matchId.value }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function unlink() {
    if (matchId.value === null) return;
    router.delete(LinkToTournamentController.destroy({ match: matchId.value }).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

function formatDate(value: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

defineExpose({ openForMatch });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[80vh] flex-col">
            <DialogHeader>
                <DialogTitle>Link to tournament</DialogTitle>
                <DialogDescription>
                    Pick a tournament and enter the round this match belongs to.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center justify-between gap-3">
                <Input v-model="search" placeholder="Search tournaments..." class="flex-1" />
                <div class="flex items-center gap-2">
                    <Label for="show-all" class="text-xs text-muted-foreground">Show more</Label>
                    <Switch id="show-all" :model-value="showAll" @update:model-value="onToggleShowAll" />
                </div>
            </div>

            <div class="flex-1 space-y-0.5 overflow-y-auto rounded-md border">
                <p v-if="loading" class="py-6 text-center text-sm text-muted-foreground">Loading...</p>
                <p v-else-if="filteredCandidates.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                    No tournaments match. Toggle "Show more" to broaden the list.
                </p>
                <button
                    v-for="candidate in filteredCandidates"
                    :key="candidate.id"
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted"
                    :class="{ 'bg-muted': candidate.id === selectedTournamentId }"
                    @click="selectedTournamentId = candidate.id"
                >
                    <div class="flex flex-col">
                        <span class="font-medium">{{ candidate.format ?? candidate.type ?? 'Tournament' }} #{{ candidate.eventId ?? candidate.id }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ formatDate(candidate.startedAt ?? candidate.scheduledAt) }}
                        </span>
                    </div>
                    <span class="text-xs text-muted-foreground">{{ candidate.type }}</span>
                </button>
            </div>

            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <Label for="round" class="text-xs">Round</Label>
                    <Input
                        id="round"
                        v-model.number="round"
                        type="number"
                        :min="1"
                        :max="selectedTournament?.maxRounds ?? undefined"
                        placeholder="e.g. 3"
                    />
                </div>
            </div>

            <DialogFooter class="flex items-center justify-between gap-2">
                <Button v-if="isLinked" variant="destructive" :disabled="form.processing" @click="unlink">
                    Unlink
                </Button>
                <div class="ml-auto flex gap-2">
                    <Button variant="outline" @click="open = false">Cancel</Button>
                    <Button
                        :disabled="!selectedTournamentId || !round || form.processing"
                        @click="save"
                    >
                        Save
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
```

- [ ] **Step 2: Start the dev server and verify the dialog opens**

```
composer run dev
```

(Skip if already running.) Defer visual verification until Task 8 wires it up.

- [ ] **Step 3: Commit**

```
git add resources/js/components/matches/LinkTournamentDialog.vue
git commit -m "feat: LinkTournamentDialog component"
```

---

## Task 8: Wire dialog into MatchesTable context menu

**Files:**
- Modify: `resources/js/components/matches/MatchesTable.vue`

- [ ] **Step 1: Add the import and dialog ref**

Add near existing imports (around line 3):

```ts
import LinkTournamentDialog from '@/components/matches/LinkTournamentDialog.vue';
```

Add near other dialog refs (around line 33):

```ts
const linkTournamentDialog = ref<InstanceType<typeof LinkTournamentDialog> | null>(null);
```

- [ ] **Step 2: Render the dialog**

In the template, near the existing dialogs (around line 126-127):

```vue
<LinkTournamentDialog ref="linkTournamentDialog" />
```

- [ ] **Step 3: Add the context menu item**

Inside `<ContextMenuContent>` (around line 256-266), add a new item (recommended placement: above "Remove from stats"):

```vue
<ContextMenuItem
    @click="linkTournamentDialog?.openForMatch(
        match.id,
        match.tournament?.id ?? null,
        match.tournamentRound ?? null,
    )"
>
    Link to tournament
</ContextMenuItem>
```

- [ ] **Step 4: Verify in the browser**

Start the dev server if not already running:

```
composer run dev
```

Manually test:
1. Visit the matches index or a deck's matches page.
2. Right-click a match → click **Link to tournament**.
3. Modal opens, candidates load (empty state if no tournaments), search filters, "Show more" toggles, round input accepts a number.
4. Select a tournament + round → Save → modal closes, match row now shows reflective state (verify via right-clicking again: the dialog pre-selects the saved tournament).
5. Right-click a linked match → open dialog → click **Unlink** → match is unlinked.
6. Verify no console errors.

Report explicitly whether each of the six steps above passed. If you can't test the UI (no browser, no dev server), say so.

- [ ] **Step 5: Commit**

```
git add resources/js/components/matches/MatchesTable.vue
git commit -m "feat: wire Link to tournament into match context menu"
```

---

## Task 9: Full verification

- [ ] **Step 1: Run the full test suite for the new files**

```
php artisan test --compact --filter='MatchData|ManuallyLinkMatchToTournament|CandidatesController|LinkToTournamentController'
```

Expected: all pass.

- [ ] **Step 2: Run Pint on all dirty files**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Sanity-check routes**

```
php artisan route:list --path=matches
php artisan route:list --path=tournaments
```

Expected: `matches.link-tournament`, `matches.unlink-tournament`, and `tournaments.candidates` all present.

- [ ] **Step 4: Run full test suite**

```
php artisan test --compact
```

Expected: all tests pass (no regressions).

- [ ] **Step 5: Final commit (only if any dirty files remain)**

```
git status
# if anything is still dirty:
git add -A
git commit -m "chore: final cleanup for manual tournament link feature"
```

---

## Self-Review Notes

- **Spec coverage:**
  - User flow (§User Flow) → Task 8 wires the menu and verifies all six flow steps.
  - `ManuallyLinkMatchToTournament` action (§Action) → Task 3.
  - `LinkToTournamentController` + routes (§Controller) → Task 5. Validation uses inline `$request->validate` (codebase convention — no Form Request classes in use).
  - `CandidatesController` (§Candidates endpoint) → Task 4. Type mapping via `TournamentType::fromPlayFormatCd($match->format)` against `$tournament->type`.
  - `TournamentCandidateData` DTO (§Candidates endpoint) → Task 2.
  - `MatchData` additions (§MatchData DTO) → Task 1.
  - `LinkTournamentDialog.vue` (§Modal) → Task 7.
  - `MatchesTable` wiring (§MatchesTable changes) → Task 8.
  - Wayfinder regeneration (§Routing) → Task 6.
  - Edge cases (§Edge Cases) — covered across tests in Tasks 4 & 5.
- **Placeholder scan:** none found.
- **Type consistency:** `selectedTournamentId`, `round`, `openForMatch`, `matches.link-tournament`, `matches.unlink-tournament`, `tournaments.candidates` used consistently. `ManuallyLinkMatchToTournament::link()` / `::unlink()` signatures match between Task 3 (definition) and Task 5 (caller).
