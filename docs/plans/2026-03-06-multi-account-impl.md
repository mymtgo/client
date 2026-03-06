# Multi-Account Context Switching — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Discover MTGO accounts from login events, store them in an `accounts` table, scope all data by active account, and provide context switching via header dropdown + auto-switch on login.

**Architecture:** An `accounts` table tracks discovered usernames with `tracked` and `active` columns. Decks get an `account_id` FK referencing `accounts.id`. IngestLog detects login events and registers/activates accounts. All consumer queries scope through the active account's decks. A dropdown in AppHeader lets users switch manually.

**Tech Stack:** PHP 8.4, Laravel 12, Pest v4, Vue 3, Inertia.js v2, NativePHP 2.0, Tailwind v4

---

### Task 1: Account model + migration

**Files:**
- Create: `app/Models/Account.php`
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_accounts_table.php`

**Step 1: Generate migration**

```bash
php artisan make:migration create_accounts_table --no-interaction
```

**Step 2: Write the migration**

```php
public function up(): void
{
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->string('username')->unique();
        $table->boolean('active')->default(false)->index();
        $table->boolean('tracked')->default(true);
        $table->softDeletes();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('accounts');
}
```

**Step 3: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'tracked' => 'boolean',
    ];

    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('tracked', true);
    }

    /**
     * Set this account as active, deactivating all others.
     */
    public function activate(): void
    {
        static::where('active', true)->update(['active' => false]);
        $this->update(['active' => true]);
    }

    /**
     * Find or create an account, and activate it.
     */
    public static function registerAndActivate(string $username): static
    {
        $account = static::firstOrCreate(
            ['username' => $username],
            ['tracked' => true]
        );

        $account->activate();

        return $account;
    }
}
```

**Step 4: Run migration**

```bash
php artisan migrate
```

**Step 5: Commit**

```bash
git add app/Models/Account.php database/migrations/*create_accounts_table*
git commit -m "Add Account model and migration"
```

---

### Task 2: Add account_id foreign key to decks

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_account_id_to_decks_table.php`

**Step 1: Generate migration**

```bash
php artisan make:migration add_account_id_to_decks_table --no-interaction
```

**Step 2: Write the migration**

```php
public function up(): void
{
    Schema::table('decks', function (Blueprint $table) {
        $table->foreignId('account_id')->nullable()->after('format')->constrained()->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('decks', function (Blueprint $table) {
        $table->dropConstrainedForeignId('account_id');
    });
}
```

Nullable so existing decks can be backfilled later. On first login detection, existing decks get assigned to the account.

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Commit**

```bash
git add database/migrations/*add_account_id_to_decks*
git commit -m "Add account_id foreign key to decks table"
```

---

### Task 3: Fix IngestLog username detection

**Files:**
- Modify: `app/Actions/Logs/IngestLog.php`
- Create: `tests/Feature/Actions/Logs/DetectLoginTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Actions\Logs\IngestLog;
use App\Models\Account;
use App\Models\LogCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects username from MtGO Login Success event', function () {
    // Simulate a log file with a login event
    $logContent = "15:52:41 [INF] (Login|MtGO Login Success) Username: testplayer (3022021)\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo_test_');
    file_put_contents($tmpFile, $logContent);

    IngestLog::run($tmpFile);

    $cursor = LogCursor::where('file_path', $tmpFile)->first();
    expect($cursor->local_username)->toBe('testplayer');

    unlink($tmpFile);
});

it('updates username when account switches mid-session', function () {
    $logContent = "15:52:41 [INF] (Login|MtGO Login Success) Username: player_one (3022021)\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo_test_');
    file_put_contents($tmpFile, $logContent);

    IngestLog::run($tmpFile);

    $cursor = LogCursor::where('file_path', $tmpFile)->first();
    expect($cursor->local_username)->toBe('player_one');

    // Append second login
    file_put_contents($tmpFile, "16:10:00 [INF] (Login|MtGO Login Success) Username: player_two (4055032)\n", FILE_APPEND);

    IngestLog::run($tmpFile);

    $cursor->refresh();
    expect($cursor->local_username)->toBe('player_two');

    unlink($tmpFile);
});

it('registers new accounts on login detection', function () {
    $logContent = "15:52:41 [INF] (Login|MtGO Login Success) Username: newplayer (3022021)\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'mtgo_test_');
    file_put_contents($tmpFile, $logContent);

    IngestLog::run($tmpFile);

    $account = Account::where('username', 'newplayer')->first();
    expect($account)->not->toBeNull();
    expect($account->tracked)->toBeTrue();
    expect($account->active)->toBeTrue();

    unlink($tmpFile);
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=DetectLogin --compact
```

Expected: FAIL

**Step 3: Update IngestLog**

In `app/Actions/Logs/IngestLog.php`, make these changes:

1. Add import at top: `use App\Models\Account;`

2. Replace the username detection blocks. There are TWO identical blocks (lines 106-112 and lines 143-149). Replace both with a new approach.

Change the detection logic in both places from:
```php
// Learn username once per log instance
if (! $cursor->local_username && $row['category'] === 'UI' && $row['context'] === 'LastLoginName') {
    $u = static::extractUsername($row['raw_text']);
    if ($u) {
        $cursor->local_username = $u;
    }
}
```

To:
```php
// Detect login events — always update (accounts can switch mid-session)
if ($row['category'] === 'Login' && $row['context'] === 'MtGO Login Success') {
    $u = static::extractLoginUsername($row['raw_text']);
    if ($u) {
        $cursor->local_username = $u;
        Account::registerAndActivate($u);
    }
}
```

3. Add new extraction method (keep the old `extractUsername` for backward compat, add new one):

```php
protected static function extractLoginUsername(string $raw): ?string
{
    if (preg_match('/Username:\s*(\S+)/', $raw, $m)) {
        return $m[1];
    }

    return null;
}
```

**Step 4: Run tests**

```bash
php artisan test --filter=DetectLogin --compact
```

Expected: PASS

**Step 5: Commit**

```bash
git add app/Actions/Logs/IngestLog.php tests/Feature/Actions/Logs/DetectLoginTest.php
git commit -m "Fix login detection to handle account switching"
```

---

### Task 4: Update SyncDecks to tag decks with account_id

**Files:**
- Modify: `app/Actions/Decks/SyncDecks.php:58-63`

**Step 1: Update SyncDecks**

Add `use App\Models\Account;` and `use App\Facades\Mtgo;` imports at top.

**Important distinction:** SyncDecks is a pipeline action — it must use the **logged-in account** (from `LogCursor.local_username` via `Mtgo::getUsername()`), NOT `Account::active()`. The active account is the UI viewing context; the logged-in account is who owns the deck files.

In the `fill()` call on line 58-63, add the account_id:

```php
$deck->fill([
    'mtgo_id' => $attributes['NetDeckId'],
    'name' => $attributes['Name'],
    'format' => $attributes['FormatCode'],
    'account_id' => Account::where('username', Mtgo::getUsername())->value('id'),
    'updated_at' => $attributes['Timestamp'],
]);
```

Also update the soft-delete on line 79 to only delete decks for the logged-in account:

```php
$accountId = Account::where('username', Mtgo::getUsername())->value('id');
if ($accountId) {
    Deck::where('account_id', $accountId)->whereNotIn('id', $deckIds)->delete();
} else {
    Deck::whereNotIn('id', $deckIds)->delete();
}
```

**Step 2: Commit**

```bash
git add app/Actions/Decks/SyncDecks.php
git commit -m "Tag synced decks with logged-in account_id"
```

---

### Task 5: Scope DetermineMatchDeck to logged-in account

**Files:**
- Modify: `app/Actions/Matches/DetermineMatchDeck.php:36`

**Step 1: Update deck version lookup**

Add `use App\Models\Account;` and `use App\Facades\Mtgo;` imports at top.

**Important distinction:** DetermineMatchDeck is a pipeline action — it must use the **logged-in account** (from `Mtgo::getUsername()`), NOT `Account::active()`. The match being built belongs to whoever is logged into MTGO, not whoever is selected in the UI.

Change line 36 from:
```php
$deckVersion = DeckVersion::where('signature', $signature)->first();
```

To:
```php
$accountId = Account::where('username', Mtgo::getUsername())->value('id');

$deckVersion = DeckVersion::where('signature', $signature)
    ->when($accountId, fn ($q) => $q->whereHas('deck', fn ($q2) => $q2->where('account_id', $accountId)))
    ->first();
```

Uses `when()` so it falls back to unscoped if no account found (backward compat for existing data).

**Step 2: Commit**

```bash
git add app/Actions/Matches/DetermineMatchDeck.php
git commit -m "Scope DetermineMatchDeck to logged-in account"
```

---

### Task 6: Gate BuildMatches on tracked status

**Files:**
- Modify: `app/Actions/Matches/BuildMatches.php:14-18`

**Step 1: Add tracking gate**

Add `use App\Models\Account;` import at top.

After line 20 (`Mtgo::setUsername($username);`), add the tracking check:

```php
$account = Account::where('username', $username)->first();

if ($account && ! $account->tracked) {
    return;
}
```

This skips match building if the account exists and is explicitly untracked. If the account doesn't exist yet (hasn't been registered from a login event), we proceed — matches will still build.

**Step 2: Commit**

```bash
git add app/Actions/Matches/BuildMatches.php
git commit -m "Gate BuildMatches on tracked account status"
```

---

### Task 7: Share active account via Inertia

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

**Step 1: Add active account + accounts list to shared data**

Add `use App\Models\Account;` import at top.

Update the `share()` method to include account data:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'status' => fn () => [
            'watcherRunning' => Mtgo::canRun(),
            'lastIngestAt' => LogEvent::max('ingested_at'),
            'pendingMatchCount' => MtgoMatch::submittable()->count(),
        ],
        'activeAccount' => fn () => Account::active()->first()?->username,
        'accounts' => fn () => Account::tracked()->orderBy('username')->get(['id', 'username', 'active']),
    ];
}
```

**Step 2: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php
git commit -m "Share active account and accounts list via Inertia"
```

---

### Task 8: Account switcher dropdown in AppHeader

**Files:**
- Modify: `resources/js/components/AppHeader.vue`
- Create: `app/Http/Controllers/Settings/SwitchAccountController.php`
- Modify: `routes/web.php`

**Step 1: Create the switch controller**

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchAccountController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => 'required|string|exists:accounts,username',
        ]);

        $account = Account::where('username', $request->input('username'))->firstOrFail();
        $account->activate();

        return redirect()->route('decks.index');
    }
}
```

**Step 2: Add route**

In `routes/web.php`, inside the settings group (after line 55), add:

```php
$group->patch('switch-account', \App\Http\Controllers\Settings\SwitchAccountController::class)->name('settings.switch-account');
```

**Step 3: Update AppHeader.vue**

```vue
<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { Settings, ChevronDown } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import SettingsIndexController from '@/actions/App/Http/Controllers/Settings/IndexController';
import SwitchAccountController from '@/actions/App/Http/Controllers/Settings/SwitchAccountController';

const page = usePage<{
    activeAccount: string | null;
    accounts: Array<{ id: number; username: string; active: boolean }>;
}>();

function switchAccount(username: string) {
    router.patch(SwitchAccountController.url(), { username }, {
        preserveScroll: false,
    });
}
</script>

<template>
    <header class="flex h-12 shrink-0 items-center justify-between bg-sidebar px-4 text-sidebar-foreground">
        <Link :href="DashboardController.url()" class="text-base font-semibold tracking-tight">
            mymtgo
        </Link>

        <div class="flex items-center gap-2">
            <DropdownMenu v-if="page.props.accounts && page.props.accounts.length > 1">
                <DropdownMenuTrigger class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground">
                    {{ page.props.activeAccount ?? 'No account' }}
                    <ChevronDown class="size-3" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="account in page.props.accounts"
                        :key="account.id"
                        @click="switchAccount(account.username)"
                        :class="{ 'font-semibold': account.active }"
                    >
                        {{ account.username }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <span v-else-if="page.props.activeAccount" class="text-sm text-sidebar-foreground/70">
                {{ page.props.activeAccount }}
            </span>

            <Link
                :href="SettingsIndexController.url()"
                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
            >
                <Settings class="size-4" />
            </Link>
        </div>
    </header>
</template>
```

The dropdown only shows when there are 2+ tracked accounts. Single account just shows the username as text.

**Step 4: Commit**

```bash
git add app/Http/Controllers/Settings/SwitchAccountController.php routes/web.php resources/js/components/AppHeader.vue
git commit -m "Add account switcher dropdown in header"
```

---

### Task 9: Scope consumer queries by active account

**Files:**
- Modify: `app/Models/Deck.php`
- Modify: `app/Http/Controllers/IndexController.php`
- Modify: `app/Http/Controllers/Decks/IndexController.php`
- Modify: `app/Http/Controllers/Leagues/IndexController.php`
- Modify: `app/Http/Controllers/Opponents/IndexController.php`

**Step 1: Add account scope to Deck model**

In `app/Models/Deck.php`, add imports and a scope + relationship:

```php
use App\Models\Account;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

```php
public function account(): BelongsTo
{
    return $this->belongsTo(Account::class);
}

public function scopeForActiveAccount(Builder $query): Builder
{
    $accountId = Account::active()->value('id');

    if ($accountId) {
        return $query->where('account_id', $accountId);
    }

    return $query;
}
```

Also add the `Builder` import if not present: `use Illuminate\Database\Eloquent\Builder;`

**Step 2: Update IndexController (dashboard)**

In `app/Http/Controllers/IndexController.php`, the stats and recent matches queries go directly to `MtgoMatch`. These need to join through to deck for account filtering.

Add `use App\Models\Account;` import.

Add a helper method:
```php
private function activeAccountId(): ?int
{
    return Account::active()->value('id');
}
```

Update stats query (line 21):
```php
$stats = MtgoMatch::complete()
    ->when($this->activeAccountId(), fn ($q, $id) => $q
        ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
    )
    ->whereBetween('started_at', [$start, $end])
```

Update recent matches (line 34):
```php
$recentMatches = MtgoMatch::complete()
    ->when($this->activeAccountId(), fn ($q, $id) => $q
        ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
    )
    ->with([...])
```

Update deck stats (line 40):
```php
$deckStats = Deck::forActiveAccount()
    ->withCount([...])
```

Update buildActiveLeague (line 66) — filter league's matches through deck:
```php
$league = League::whereHas('matches', function ($q) {
    $q->complete();
    if ($id = $this->activeAccountId()) {
        $q->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)));
    }
})->latest('started_at')->first();
```

Update buildFormatChart (line 103):
```php
$rows = MtgoMatch::complete()
    ->when($this->activeAccountId(), fn ($q, $id) => $q
        ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
    )
```

**Step 3: Update Decks/IndexController**

In `app/Http/Controllers/Decks/IndexController.php` (line 14):
```php
$decks = Deck::forActiveAccount()
    ->withCount([...])
```

**Step 4: Update Leagues/IndexController**

In `app/Http/Controllers/Leagues/IndexController.php`, add `use App\Models\Account;` import.

After line 17, get the active account ID:
```php
$activeAccountId = Account::active()->value('id');
```

In the raw DB query (line 31), add account_id filter:
```php
$matchRows = DB::table('matches as m')
    ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
    ->join('decks as d', 'd.id', '=', 'dv.deck_id')
    ->whereIn('m.league_id', $leagueIds)
    ->whereNull('m.deleted_at')
    ->where('m.state', 'complete')
    ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
```

**Step 5: Update Opponents/IndexController**

In `app/Http/Controllers/Opponents/IndexController.php`, add `use App\Models\Account;` import.

Add account_id filter to the raw query (line 16):
```php
$activeAccountId = Account::active()->value('id');

$opponentMatches = DB::table('game_player as gp')
    ->join('players as p', 'p.id', '=', 'gp.player_id')
    ->join('games as g', 'g.id', '=', 'gp.game_id')
    ->join('matches as m', 'm.id', '=', 'g.match_id')
    ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
    ->join('decks as d', 'd.id', '=', 'dv.deck_id')
    ->where('gp.is_local', false)
    ->whereNull('m.deleted_at')
    ->where('m.state', 'complete')
    ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
```

**Step 6: Commit**

```bash
git add app/Models/Deck.php app/Http/Controllers/IndexController.php \
    app/Http/Controllers/Decks/IndexController.php \
    app/Http/Controllers/Leagues/IndexController.php \
    app/Http/Controllers/Opponents/IndexController.php
git commit -m "Scope all consumer queries by active account"
```

---

### Task 10: Settings page — Accounts section

**Files:**
- Modify: `app/Http/Controllers/Settings/IndexController.php`
- Create: `app/Http/Controllers/Settings/UpdateAccountTrackingController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/pages/settings/Index.vue`

**Step 1: Create the tracking toggle controller**

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateAccountTrackingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => 'required|string|exists:accounts,username',
            'tracked' => 'required|boolean',
        ]);

        Account::where('username', $request->input('username'))
            ->update(['tracked' => $request->boolean('tracked')]);

        return back();
    }
}
```

**Step 2: Add route**

In `routes/web.php` settings group, add:

```php
$group->patch('account-tracking', \App\Http\Controllers\Settings\UpdateAccountTrackingController::class)->name('settings.account-tracking');
```

**Step 3: Update Settings/IndexController**

Add `use App\Models\Account;` import.

Add to the Inertia props array:

```php
'accounts' => Account::orderBy('username')->get(['id', 'username', 'tracked', 'active']),
```

**Step 4: Update settings/Index.vue**

Add to props type:
```typescript
accounts: Array<{ id: number; username: string; tracked: boolean; active: boolean }>;
```

Add Wayfinder import:
```typescript
import UpdateAccountTrackingController from '@/actions/App/Http/Controllers/Settings/UpdateAccountTrackingController';
```

Add toggle function:
```typescript
function toggleAccountTracking(username: string, tracked: boolean) {
    withProcessing(`account-${username}`, 'patch', UpdateAccountTrackingController.url(), { username, tracked });
}
```

Add new section in template, before the "File Paths" section:

```html
<!-- Accounts -->
<div v-if="accounts.length" class="flex flex-col gap-4 p-3 lg:p-4">
    <div>
        <p class="font-semibold">Accounts</p>
        <p class="text-sm text-muted-foreground">
            MTGO accounts detected from your log files. Toggle tracking to control which accounts record match data.
        </p>
    </div>

    <div v-for="account in accounts" :key="account.id" class="flex items-start gap-3">
        <Checkbox
            :id="`account-${account.username}`"
            :defaultValue="account.tracked"
            @update:modelValue="(checked: boolean | 'indeterminate') => toggleAccountTracking(account.username, checked === true)"
            :disabled="processing === `account-${account.username}`"
        />
        <div class="flex flex-col gap-1">
            <Label :for="`account-${account.username}`">{{ account.username }}</Label>
            <p class="text-sm text-muted-foreground">
                <Badge v-if="account.active" variant="default" class="text-xs">Active</Badge>
                {{ account.tracked ? 'Recording matches' : 'Not recording matches' }}
            </p>
        </div>
    </div>
</div>
```

**Step 5: Commit**

```bash
git add app/Http/Controllers/Settings/IndexController.php \
    app/Http/Controllers/Settings/UpdateAccountTrackingController.php \
    routes/web.php resources/js/pages/settings/Index.vue
git commit -m "Add Accounts section to Settings page"
```

---

### Task 11: Backfill existing decks + register first account

**Files:**
- Create: `app/Console/Commands/BackfillDeckAccounts.php`

**Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Deck;
use App\Models\LogCursor;
use Illuminate\Console\Command;

class BackfillDeckAccounts extends Command
{
    protected $signature = 'decks:backfill-accounts';

    protected $description = 'Assign existing decks to the current MTGO account and register it';

    public function handle(): int
    {
        $username = LogCursor::first()?->local_username;

        if (! $username) {
            $this->warn('No username found in LogCursor. Run the app and log into MTGO first.');

            return self::FAILURE;
        }

        $account = Account::registerAndActivate($username);
        $this->info("Registered account: {$account->username}");

        $updated = Deck::whereNull('account_id')->update(['account_id' => $account->id]);
        $this->info("Backfilled {$updated} deck(s) with account: {$account->username}");

        return self::SUCCESS;
    }
}
```

This should also be called automatically during `MtgoManager::runInitialSetup()` to handle upgrades. Add to `app/Managers/MtgoManager.php` in `runInitialSetup()`, after the username is available:

```php
// Register account from existing cursor data (upgrade path)
if ($this->getUsername() && !\App\Models\Account::exists()) {
    $account = \App\Models\Account::registerAndActivate($this->getUsername());
    \App\Models\Deck::whereNull('account_id')->update(['account_id' => $account->id]);
}
```

**Step 2: Commit**

```bash
git add app/Console/Commands/BackfillDeckAccounts.php app/Managers/MtgoManager.php
git commit -m "Add deck account backfill command and auto-upgrade in setup"
```

---

### Task 12: Pint + full test suite + final verification

**Step 1: Run Pint**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run full test suite**

```bash
php artisan test --compact
```

**Step 3: Verify routes**

```bash
php artisan route:list
```

**Step 4: Commit any fixes**

```bash
git add -A
git commit -m "pint"
```

---

## Task Dependency Order

```
Task 1 (accounts table) ──→ Task 2 (username on decks)
         │                          │
         │                          ▼
         │                   Task 4 (SyncDecks tags username)
         │                          │
         │                          ▼
         │                   Task 5 (DetermineMatchDeck scoping)
         │
         ├──→ Task 3 (IngestLog login detection)
         │
         ├──→ Task 6 (BuildMatches gate)
         │
         ├──→ Task 7 (Inertia shared data)
         │         │
         │         ▼
         │    Task 8 (AppHeader dropdown)
         │
         ├──→ Task 9 (consumer query scoping)
         │
         ├──→ Task 10 (Settings UI)
         │
         └──→ Task 11 (backfill + upgrade)
                   │
                   ▼
              Task 12 (final verification)
```

Tasks 3, 4, 6, 7, 9, 10, 11 can run in parallel after Tasks 1-2. Task 8 depends on Task 7. Task 12 runs last.
