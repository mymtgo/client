# Pipeline Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give users visibility into the match pipeline via structured logging and a debug viewer, plus tools to force-delete and reset matches.

**Architecture:** A dedicated `pipeline` log channel captures decision-level messages throughout the match pipeline (IngestLog → BuildMatches → AdvanceMatchState → CreateGames → ResolveStaleMatches). A new debug page displays the log newest-first with date selection and text filtering. A `PurgeMatch` action handles cascading hard-delete + event reset, exposed via two UI entry points on the debug Matches page.

**Tech Stack:** Laravel 12 log channels, Inertia.js single-action controllers, Vue 3

**Spec:** `docs/superpowers/specs/2026-03-16-pipeline-diagnostics-design.md`

---

## Chunk 1: Pipeline Log Channel + Logging

### Task 1: Add pipeline log channel

**Files:**
- Modify: `config/logging.php:53-130` (add channel to `channels` array)

- [ ] **Step 1: Add the pipeline channel**

In `config/logging.php`, add after the `'emergency'` channel (before the closing `],` of the `'channels'` array):

```php
'pipeline' => [
    'driver' => 'daily',
    'path' => storage_path('logs/pipeline.log'),
    'level' => 'debug',
    'days' => 7,
    'replace_placeholders' => true,
],
```

- [ ] **Step 2: Verify the channel works**

Run: `php artisan tinker --execute="Log::channel('pipeline')->info('test'); echo 'ok';"`

Check that `storage/logs/pipeline-2026-03-16.log` was created with the test message.

- [ ] **Step 3: Commit**

```bash
git add config/logging.php
git commit -m "Add pipeline log channel (daily, 7 day retention)"
```

### Task 2: Add logging to IngestLog

**Files:**
- Modify: `app/Actions/Logs/IngestLog.php`

Reference: Read the full file first. The key insertion points are:
- After rotation detection (line ~51): log rotation event
- After the batch loop completes (line ~145, before `DB::transaction`): log ingestion summary + classification summary
- After cursor skip at line ~64: log "nothing new"

- [ ] **Step 1: Add logging imports**

At the top of the file, add:

```php
use Illuminate\Support\Facades\Log;
```

- [ ] **Step 2: Add rotation detection logging**

After the `if ($truncated || $replaced || $mtimeBackwards)` block (around line 54), add:

```php
if ($truncated || $replaced || $mtimeBackwards) {
    $cursor->byte_offset = 0;
    $cursor->local_username = null;

    Log::channel('pipeline')->info('Log rotation detected — cursor reset to 0', [
        'file' => $logPath,
        'reason' => $truncated ? 'truncated' : ($replaced ? 'replaced' : 'mtime_backwards'),
    ]);
}
```

- [ ] **Step 3: Add ingestion summary logging**

After the `$hadNewEvents = ! empty($rows);` line (around line 145), before the `DB::transaction`, add:

```php
if ($hadNewEvents) {
    $classified = collect($rows)->whereNotNull('event_type')->countBy('event_type');
    $unclassified = collect($rows)->whereNull('event_type')->count();

    Log::channel('pipeline')->info("Ingested " . count($rows) . " events from {$logPath}", [
        'cursor' => "{$cursor->byte_offset} → {$safeOffset}",
        'classified' => $classified->toArray(),
        'unclassified' => $unclassified,
    ]);
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Actions/Logs/IngestLog.php
git commit -m "Add pipeline logging to IngestLog"
```

### Task 3: Add logging to BuildMatches

**Files:**
- Modify: `app/Actions/Matches/BuildMatches.php`

Reference: Read the full file first. Existing `Log::debug()` calls should be changed to `Log::channel('pipeline')`.

- [ ] **Step 1: Replace existing debug logging with pipeline channel**

Change all `Log::debug(...)` calls to `Log::channel('pipeline')->info(...)`. There are 5 existing calls:
- Line 27: token/ID counts
- Line 39: no username skip
- Line 47: untracked account skip
- Line 54: creating match
- Line 56: AdvanceMatchState result

- [ ] **Step 2: Commit**

```bash
git add app/Actions/Matches/BuildMatches.php
git commit -m "Route BuildMatches logging to pipeline channel"
```

### Task 4: Add logging to AdvanceMatchState

**Files:**
- Modify: `app/Actions/Matches/AdvanceMatchState.php`

Reference: Read the full file first. This is the most important file for logging — each state transition and failure needs a message.

- [ ] **Step 1: Replace existing Log::debug with pipeline channel**

Change the existing `Log::debug(...)` at line 55 to `Log::channel('pipeline')->warning(...)`.

- [ ] **Step 2: Add logging after match creation (line ~86)**

After the `MtgoMatch::create(...)` block:

```php
Log::channel('pipeline')->info("Match {$matchId}: created in Started state", [
    'token' => $matchToken,
    'format' => $match->format,
    'match_type' => $match->match_type,
]);
```

- [ ] **Step 3: Add logging in tryAdvanceToInProgress**

After the `$gameStateEvents->isEmpty()` check (line ~140):

```php
if ($gameStateEvents->isEmpty()) {
    Log::channel('pipeline')->warning("Match {$match->mtgo_id}: Started → InProgress FAILED", [
        'reason' => '0 game_state_update events',
        'total_events' => $events->count(),
        'event_types' => $events->pluck('event_type')->countBy()->toArray(),
    ]);
    return false;
}
```

After the state update to InProgress (line ~169):

```php
Log::channel('pipeline')->info("Match {$match->mtgo_id}: Started → InProgress", [
    'game_state_events' => $gameStateEvents->count(),
    'game_ids' => $gameStateEvents->pluck('game_id')->unique()->values()->toArray(),
    'deck_linked' => (bool) $match->deck_version_id,
    'league_id' => $match->league_id,
]);
```

- [ ] **Step 4: Add logging for gameMeta extraction**

After `$gameMeta ??= ExtractKeyValueBlock::run($joinedState->raw_text);` (line ~93):

```php
Log::channel('pipeline')->info("Match {$match->mtgo_id}: gameMeta keys", [
    'keys' => array_keys($gameMeta),
    'has_league_token' => ! empty($gameMeta['League Token']),
]);
```

- [ ] **Step 5: Add logging in tryAdvanceToEnded**

After the `! $matchEnded && ! $concededAndQuit` check (line ~195):

```php
if (! $matchEnded && ! $concededAndQuit) {
    Log::channel('pipeline')->info("Match {$match->mtgo_id}: InProgress → Ended waiting", [
        'state_changes' => $stateChanges->count(),
        'contexts' => $stateChanges->pluck('context')->toArray(),
    ]);
    return false;
}
```

After the state update to Ended (line ~206):

```php
Log::channel('pipeline')->info("Match {$match->mtgo_id}: InProgress → Ended", [
    'signal' => $matchEnded ? $matchEnded->context : 'local_concede',
]);
```

- [ ] **Step 6: Add logging in tryAdvanceToComplete**

After the match update to Complete (line ~235):

```php
Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → Complete", [
    'result' => "{$result['wins']}-{$result['losses']}",
    'game_log_results' => count($logResults),
]);
```

- [ ] **Step 7: Add logging for league assignment**

In `assignLeague`, after the league is created/found. After the `$match->update(['league_id' => $league->id])` (line ~347):

```php
Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
    'league_name' => $league->name,
    'phantom' => $league->phantom,
    'has_league_token' => ! empty($gameMeta['League Token']),
]);
```

- [ ] **Step 8: Commit**

```bash
git add app/Actions/Matches/AdvanceMatchState.php
git commit -m "Add pipeline logging to AdvanceMatchState at each transition"
```

### Task 5: Add logging to ResolveStaleMatches

**Files:**
- Modify: `app/Actions/Matches/ResolveStaleMatches.php`

Reference: Read the full file first. Currently has zero logging.

- [ ] **Step 1: Add logging import**

```php
use Illuminate\Support\Facades\Log;
```

- [ ] **Step 2: Add logging for evaluation start**

After `$latestMatchStart` is computed (line ~30):

```php
Log::channel('pipeline')->info("ResolveStaleMatches: evaluating {$incompleteMatches->count()} incomplete matches", [
    'latest_start' => $latestMatchStart,
]);
```

- [ ] **Step 3: Add logging for each match verdict**

After the `continue` for non-stale matches (line ~35):

```php
if ($match->started_at >= $latestMatchStart) {
    Log::channel('pipeline')->info("ResolveStaleMatches: match {$match->mtgo_id} skipped (is latest)");
    continue;
}
```

After the final advance attempt, log the verdict. After the `$isRealLeague` block (line ~61 for voided, line ~51 for ended):

For the voided case:
```php
$match->update(['state' => MatchState::Voided]);
Log::channel('pipeline')->warning("ResolveStaleMatches: match {$match->mtgo_id} voided", [
    'reason' => 'stale, phantom/casual league',
    'state_before' => $match->getOriginal('state'),
    'started_at' => $match->started_at,
]);
```

For the ended case:
```php
$match->update(['state' => MatchState::Ended]);
Log::channel('pipeline')->warning("ResolveStaleMatches: match {$match->mtgo_id} ended (incomplete)", [
    'reason' => 'stale, real league',
    'state_before' => $match->getOriginal('state'),
    'started_at' => $match->started_at,
]);
```

- [ ] **Step 4: Commit**

```bash
git add app/Actions/Matches/ResolveStaleMatches.php
git commit -m "Add pipeline logging to ResolveStaleMatches"
```

### Task 6: Add logging to CreateGames

**Files:**
- Modify: `app/Actions/Matches/CreateGames.php`

Reference: Read the full file first. Existing `Log::debug` and `Log::warning` calls should be routed to pipeline channel.

- [ ] **Step 1: Route existing logging to pipeline channel**

Change all `Log::debug(...)` and `Log::warning(...)` calls to use `Log::channel('pipeline')`:
- Line 54: `Log::channel('pipeline')->info(...)`
- Line 63: `Log::channel('pipeline')->warning(...)`
- Line 141: `Log::channel('pipeline')->info(...)`

- [ ] **Step 2: Add game creation and player sync logging**

After the `$gameModel->players()->sync(...)` call (line ~113), add:

```php
Log::channel('pipeline')->info("Match {$match->mtgo_id}: game {$gameId} — " . ($gameModel->wasRecentlyCreated ? 'created' : 'updated') . ", {$gameModel->players()->count()} players synced");
```

- [ ] **Step 3: Commit**

```bash
git add app/Actions/Matches/CreateGames.php
git commit -m "Route CreateGames logging to pipeline channel"
```

---

## Chunk 2: Pipeline Log Viewer

### Task 7: Create PipelineLog controller

**Files:**
- Create: `app/Http/Controllers/Debug/PipelineLog/IndexController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Debug\PipelineLog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $date = $request->get('date', now()->format('Y-m-d'));
        $filter = $request->get('filter', '');

        $path = storage_path("logs/pipeline-{$date}.log");

        $lines = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_reverse($lines);

            if ($filter !== '') {
                $lines = array_values(array_filter(
                    $lines,
                    fn (string $line) => str_contains(strtolower($line), strtolower($filter))
                ));
            }

            $lines = array_slice($lines, 0, 5000);
        }

        return Inertia::render('debug/PipelineLog', [
            'lines' => $lines,
            'date' => $date,
            'filter' => $filter,
        ]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Debug/PipelineLog/IndexController.php
git commit -m "Add PipelineLog debug controller"
```

### Task 8: Create PipelineLog Vue page

**Files:**
- Create: `resources/js/pages/debug/PipelineLog.vue`

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

const { add: toast } = useToast();

const props = defineProps<{
    lines: string[];
    date: string;
    filter: string;
}>();

const dateInput = ref(props.date);
const filterInput = ref(props.filter);
const [refreshing, startRefreshing] = useSpinGuard();

function applyFilters() {
    router.get('/debug/pipeline-log', {
        date: dateInput.value,
        filter: filterInput.value || undefined,
    }, { preserveScroll: true });
}

function refresh() {
    const stop = startRefreshing();
    router.reload({
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Log refreshed.', duration: 2000 }),
        onFinish: stop,
    });
}

function levelClass(line: string): string {
    if (line.includes('.ERROR:') || line.includes('.error:')) return 'text-red-400';
    if (line.includes('.WARNING:') || line.includes('.warning:')) return 'text-amber-400';
    return 'text-muted-foreground';
}
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
            <div class="mb-4 flex items-center gap-2">
                <Input
                    v-model="dateInput"
                    type="date"
                    class="h-8 w-40 text-xs"
                    @change="applyFilters"
                />
                <Input
                    v-model="filterInput"
                    type="text"
                    placeholder="Filter log lines..."
                    class="h-8 w-64 text-xs"
                    @keyup.enter="applyFilters"
                />
                <Button size="sm" variant="outline" class="h-8" @click="applyFilters">
                    Filter
                </Button>
                <div class="flex-1" />
                <Button size="sm" variant="outline" class="h-8" @click="refresh">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </Button>
            </div>

            <div v-if="lines.length === 0" class="py-12 text-center text-sm text-muted-foreground">
                No log entries for this date.
            </div>

            <div v-else class="rounded-lg border border-border bg-background p-3">
                <pre class="text-xs leading-relaxed"><template
                    v-for="(line, i) in lines"
                    :key="i"
                ><span :class="levelClass(line)">{{ line }}
</span></template></pre>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/pages/debug/PipelineLog.vue
git commit -m "Add PipelineLog debug Vue page"
```

### Task 9: Add route and nav tab

**Files:**
- Modify: `routes/web.php:146` (add route before closing `});` of debug group)
- Modify: `resources/js/components/debug/DebugNav.vue:12` (add tab)

- [ ] **Step 1: Add the route**

In `routes/web.php`, inside the debug group, after the Log Cursors route (line ~146), add:

```php
// Pipeline Log
$group->get('pipeline-log', App\Http\Controllers\Debug\PipelineLog\IndexController::class)->name('debug.pipeline-log.index');
```

- [ ] **Step 2: Add the nav tab**

In `resources/js/components/debug/DebugNav.vue`, add to the `tabs` array after the Log Cursors entry:

```ts
{ label: 'Pipeline Log', href: '/debug/pipeline-log' },
```

- [ ] **Step 3: Verify the page loads**

Run: `php artisan serve` (or however the dev server runs), navigate to `/debug/pipeline-log`. Should see the empty state message.

- [ ] **Step 4: Commit**

```bash
git add routes/web.php resources/js/components/debug/DebugNav.vue
git commit -m "Wire up pipeline log route and debug nav tab"
```

---

## Chunk 3: PurgeMatch Action + Force Delete + Reset & Rebuild

### Task 10: Create PurgeMatch action

**Files:**
- Create: `app/Actions/Matches/PurgeMatch.php`

Reference: Check the migrations for exact table/column names:
- `match_archetypes.mtgo_match_id` → FK to matches.id
- `game_timelines.game_id` → FK to games.id
- `game_player.game_id` → FK to games.id
- `games.match_id` → FK to matches.id
- `log_events.match_id` (string, stores mtgo_id), `log_events.match_token`, `log_events.game_id`

- [ ] **Step 1: Create the action**

```php
<?php

namespace App\Actions\Matches;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeMatch
{
    /**
     * Hard-delete a match and all related records, then reset
     * associated log events so the pipeline can rebuild from scratch.
     *
     * Returns the number of log events reset.
     */
    public static function run(MtgoMatch $match): int
    {
        return DB::transaction(function () use ($match) {
            $gameIds = $match->games()->pluck('id');
            $gameMtgoIds = $match->games()->pluck('mtgo_id');

            // 1. match_archetypes
            DB::table('match_archetypes')
                ->where('mtgo_match_id', $match->id)
                ->delete();

            // 2. game_timelines
            if ($gameIds->isNotEmpty()) {
                DB::table('game_timelines')
                    ->whereIn('game_id', $gameIds)
                    ->delete();
            }

            // 3. game_player
            if ($gameIds->isNotEmpty()) {
                DB::table('game_player')
                    ->whereIn('game_id', $gameIds)
                    ->delete();
            }

            // 4. games
            Game::where('match_id', $match->id)->delete();

            // 5. the match itself
            $mtgoId = $match->mtgo_id;
            $token = $match->token;
            $match->forceDelete();

            // 6. reset log events for reingestion
            $resetCount = LogEvent::where(function ($q) use ($mtgoId, $token, $gameMtgoIds) {
                $q->where('match_id', $mtgoId)
                    ->orWhere('match_token', $token);

                if ($gameMtgoIds->isNotEmpty()) {
                    $q->orWhereIn('game_id', $gameMtgoIds);
                }
            })->update(['processed_at' => null]);

            Log::channel('pipeline')->info("Match {$mtgoId}: purged — reset {$resetCount} log events for reingestion", [
                'token' => $token,
                'games_deleted' => $gameIds->count(),
            ]);

            return $resetCount;
        });
    }

    /**
     * Reset log events by match identifier without requiring a match record.
     * Used when events exist but no match was created.
     */
    public static function resetEventsByIdentifier(string $identifier): int
    {
        $resetCount = LogEvent::where(function ($q) use ($identifier) {
            $q->where('match_id', $identifier)
                ->orWhere('match_token', $identifier);
        })->update(['processed_at' => null]);

        if ($resetCount > 0) {
            Log::channel('pipeline')->info("Manual reset: {$resetCount} events reset for identifier {$identifier} (no match record found)");
        }

        return $resetCount;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Actions/Matches/PurgeMatch.php
git commit -m "Add PurgeMatch action for cascade delete + event reset"
```

### Task 11: Create ForceDeleteController

**Files:**
- Create: `app/Http/Controllers/Debug/Matches/ForceDeleteController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\PurgeMatch;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class ForceDeleteController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        $match = MtgoMatch::withTrashed()->findOrFail($id);

        PurgeMatch::run($match);

        return back();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Debug/Matches/ForceDeleteController.php
git commit -m "Add ForceDeleteController for debug matches"
```

### Task 12: Create ResetController

**Files:**
- Create: `app/Http/Controllers/Debug/Matches/ResetController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\PurgeMatch;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResetController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
        ]);

        $identifier = $request->input('identifier');

        $match = MtgoMatch::withTrashed()
            ->where('mtgo_id', $identifier)
            ->orWhere('token', $identifier)
            ->first();

        if ($match) {
            $resetCount = PurgeMatch::run($match);
        } else {
            $resetCount = PurgeMatch::resetEventsByIdentifier($identifier);
        }

        return back()->with('resetCount', $resetCount);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Debug/Matches/ResetController.php
git commit -m "Add ResetController for match reset & rebuild"
```

### Task 13: Add routes for force delete and reset

**Files:**
- Modify: `routes/web.php:121-123` (add routes in the Matches section of debug group)

- [ ] **Step 1: Add the routes**

In `routes/web.php`, in the debug group Matches section, after the existing routes (after line ~123), add:

```php
$group->delete('matches/{match}/force', App\Http\Controllers\Debug\Matches\ForceDeleteController::class)->name('debug.matches.force-delete');
$group->post('matches/reset', App\Http\Controllers\Debug\Matches\ResetController::class)->name('debug.matches.reset');
```

- [ ] **Step 2: Commit**

```bash
git add routes/web.php
git commit -m "Add force-delete and reset routes for debug matches"
```

### Task 14: Update Matches.vue with Force Delete button and Reset form

**Files:**
- Modify: `resources/js/pages/debug/Matches.vue`

- [ ] **Step 1: Add Force Delete function**

In the `<script setup>`, after the `restoreMatch` function (line ~59), add:

```ts
function forceDeleteMatch(id: number) {
    if (!confirm('Permanently delete this match and reset its log events? This cannot be undone.')) return;
    router.delete(`/debug/matches/${id}/force`, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Purged', message: `Match #${id} permanently deleted. Log events reset for reingestion.`, duration: 3000 }),
    });
}
```

- [ ] **Step 2: Add Reset & Rebuild state and function**

In the `<script setup>`, change the existing vue import on line 10 from `import { reactive } from 'vue'` to `import { reactive, ref } from 'vue'`. Then add:

```ts
const resetIdentifier = ref('');
const [resetting, startResetting] = useSpinGuard();

function resetMatch() {
    if (!resetIdentifier.value) return;
    const stop = startResetting();
    router.post('/debug/matches/reset', { identifier: resetIdentifier.value }, {
        preserveScroll: true,
        onSuccess: () => {
            toast({ type: 'success', title: 'Reset', message: `Match ${resetIdentifier.value} purged and events reset for reingestion.`, duration: 3000 });
            resetIdentifier.value = '';
        },
        onFinish: stop,
    });
}
```

Note: `ref` is already imported as `reactive` — add `ref` to the existing vue import.

- [ ] **Step 3: Add Reset & Rebuild form to the template**

In the template, inside the button bar `<div class="mb-4 flex items-center justify-end gap-2">`, add at the start (before Process Now):

```vue
<Input
    v-model="resetIdentifier"
    type="text"
    placeholder="Match ID or token..."
    class="h-8 w-48 text-xs"
    @keyup.enter="resetMatch"
/>
<Button size="sm" variant="outline" class="h-8" :disabled="!resetIdentifier || resetting" @click="resetMatch">
    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': resetting }" />
    Reset &amp; Rebuild
</Button>
<div class="flex-1" />
```

Also add the `Input` import at the top:

```ts
import { Input } from '@/components/ui/input';
```

- [ ] **Step 4: Add Force Delete button to the actions column**

In the template, in the actions `<td>` (line ~144), update to show Force Delete for soft-deleted matches alongside Restore:

```vue
<td class="px-2 py-1 whitespace-nowrap">
    <template v-if="match.deleted_at">
        <Button
            variant="outline"
            size="sm"
            class="h-7 text-xs mr-1"
            @click="restoreMatch(match.id as number)"
        >
            Restore
        </Button>
        <Button
            variant="ghost"
            size="sm"
            class="h-7 text-xs text-destructive"
            @click="forceDeleteMatch(match.id as number)"
        >
            Force Delete
        </Button>
    </template>
    <Button
        v-else
        variant="ghost"
        size="sm"
        class="h-7 text-xs text-destructive"
        @click="deleteMatch(match.id as number)"
    >
        Delete
    </Button>
</td>
```

- [ ] **Step 5: Verify the page compiles**

Run: `npm run build` (or `npm run dev`)
Expected: No TypeScript/build errors.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/debug/Matches.vue
git commit -m "Add Force Delete button and Reset & Rebuild form to debug Matches page"
```
