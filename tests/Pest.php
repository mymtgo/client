<?php

use App\Actions\Logs\IngestLogInstance;
use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Actions\Pipeline\RunPipeline;
use App\Actions\WhatsNew\WhatsNewContent;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Http\Middleware\HandleInertiaRequests;
use App\Managers\MtgoManager;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\League;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Database\Factories\DraftPickFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Window;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();

        // Parallel workers share tests/storage, so each gets its own sync
        // activity feed: otherwise one worker's "Sync complete." line ends
        // another worker's run mid-test.
        if ($token = ParallelTesting::token()) {
            config(['logging.channels.sync.path' => storage_path("logs/sync_{$token}.log")]);
        }

        // Reset request-scoped Account cache between tests.
        Account::flushCurrent();

        // The what's-new redirect would bounce any page load in a test that
        // has matches. Point it at a missing file; its own tests opt back in.
        WhatsNewContent::usePath('/nonexistent/whats-new.md');

        // NativePHP facades make HTTP calls to localhost:4000 (the Electron
        // backend) which doesn't exist in CI or during testing.
        Http::fake();
        Window::fake()
            ->alwaysReturnWindows([
                new Native\Desktop\Windows\Window('main'),
            ]);

        // Legacy NativePHP Settings swap — kept during migration while call sites
        // are replaced. Remove once all production code uses AppSettings.
        Settings::swap(new class
        {
            protected array $store = [];

            public function get(string $key, $default = null): mixed
            {
                return $this->store[$key] ?? ($default instanceof Closure ? $default() : $default);
            }

            public function set(string $key, $value): void
            {
                $this->store[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->store[$key]);
            }

            public function clear(): void
            {
                $this->store = [];
            }
        });

        // AppSettings: an in-memory subclass that overrides the storage
        // primitives. Typed accessors defined on the parent class fall through
        // to these, so no per-method stubbing is required.
        AppSettings::swap(new class extends App\Settings\AppSettings
        {
            protected array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->store[$key]);
            }

            // isOffline() on the real AppSettings reads settings.json directly
            // (via a private method, so this override can't fall through to
            // it) to fail closed on a read failure that get()'s default
            // can't express. The in-memory store here never fails to read,
            // so there's nothing to fail closed on — just read the store like
            // every other accessor.
            public function isOffline(): bool
            {
                return (bool) ($this->store['offline_mode'] ?? false);
            }

            // Same private-method-resolution problem as isOffline() above:
            // offlineModeNeverSet() also reads settings.json directly on the
            // real class. The in-memory store can't fail a read, so "never
            // set" here just means the key is absent from the store.
            public function offlineModeNeverSet(): bool
            {
                return ! array_key_exists('offline_mode', $this->store);
            }
        });
    })
    ->in('Feature');

/*
 * DraftPickFactory's default ordinal walks a process-wide counter, so without
 * a rewind the ordinals a test sees depend on whatever ran before it.
 *
 * SyncDraftNotesWindowVisibility memoizes the last window state it pushed for
 * the same reason: without a rewind, whether a test's run() reaches the window
 * API depends on the test before it.
 */
pest()->beforeEach(function () {
    DraftPickFactory::resetOrdinals();
    SyncDraftNotesWindowVisibility::reset();
})->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Limited fixture helpers
|--------------------------------------------------------------------------
*/

/**
 * A deck version whose deck is enabled for cloud sync, the precondition for
 * any match or league hanging off it to sync at all. Lives here rather than
 * beside the other sync helpers so a single sync test file still runs on its
 * own.
 */
function syncEnabledDeckVersion(): DeckVersion
{
    $deck = Deck::factory()->create(['cloud_sync_enabled' => true]);

    return DeckVersion::factory()->create(['deck_id' => $deck->id]);
}

function ingestFixtureLog(string $name, string $date = '2026-08-22'): string
{
    $source = base_path("tests/Fixtures/logs/{$name}");
    $target = sys_get_temp_dir().'/mymtgo_fixture_'.bin2hex(random_bytes(4)).'_'.$name;

    copy($source, $target);
    register_shutdown_function(static function () use ($target): void {
        @unlink($target);
    });

    $mtime = Carbon\Carbon::parse("{$date} 13:00:00", 'UTC')->getTimestamp();
    touch($target, $mtime, $mtime);

    drainLogFile($target);

    return $target;
}

/**
 * Ingest a log file to EOF. Production reads at most MAX_BYTES_PER_TICK per
 * tick and the fixtures are larger than one tick, so this stands in for the
 * pipeline having caught up on the file.
 */
function drainLogFile(string $path): void
{
    do {
        $before = LogCursor::query()->sum('byte_offset');
        IngestLogInstance::run($path);
    } while (LogCursor::query()->sum('byte_offset') > $before);
}

/**
 * Request only the named props of an already-rendered Inertia page, the way
 * the client fetches Inertia::defer props after first paint. The asset version
 * is resolved from the middleware so a built manifest does not answer 409.
 *
 * @param  array<int, string>  $props
 */
function inertiaPartial(string $url, string $component, array $props): TestResponse
{
    $version = app(HandleInertiaRequests::class)->version(request());

    return test()->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => implode(',', $props),
    ]);
}

function mockMtgoManagerForPipeline(): void
{
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir());
    app()->instance('mtgo', $mock);
}

function runPipelineUntilIdle(int $maxTicks = 20): int
{
    $types = [...LogEventType::draftValues(), 'game_management_json'];

    for ($tick = 1; $tick <= $maxTicks; $tick++) {
        RunPipeline::run();

        $pending = LogEvent::query()
            ->whereIn('event_type', $types)
            ->whereNull('processed_at')
            ->exists();

        if (! $pending) {
            return $tick;
        }
    }

    return $maxTicks;
}

/*
|--------------------------------------------------------------------------
| Manual match fixture
|--------------------------------------------------------------------------
*/

/**
 * A complete manual (or tracked, when $manual is false) match with two games
 * and a four-card deck version, for game-detail endpoint tests.
 *
 * @return array{match: MtgoMatch, version: DeckVersion, games: Collection<int, Game>, local: Player, opponent: Player, cards: array<string, Card>}
 */
function createManualMatchFixture(bool $manual = true): array
{
    $cards = [
        'bolt' => Card::factory()->create(['mtgo_id' => 4001, 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']),
        'goyf' => Card::factory()->create(['mtgo_id' => 4002, 'oracle_id' => 'o-goyf', 'name' => 'Tarmogoyf', 'type' => 'Creature']),
        'sb' => Card::factory()->create(['mtgo_id' => 4003, 'oracle_id' => 'o-sb', 'name' => 'Rest in Peace', 'type' => 'Enchantment']),
        'land' => Card::factory()->create(['mtgo_id' => 4004, 'oracle_id' => 'o-land', 'name' => 'Mountain', 'type' => 'Basic Land']),
    ];

    $version = DeckVersion::factory()->create([
        'signature' => base64_encode('4001:4:false|4002:4:false|4004:20:false|4003:3:true'),
    ]);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'state' => MatchState::Complete,
        'manual' => $manual,
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(15),
    ]);

    $local = Player::firstOrCreate(['username' => 'testplayer']);
    $opponent = Player::firstOrCreate(['username' => 'opponent']);

    $games = collect([
        ['won' => true, 'on_play' => true, 'started_at' => now()->subHour()],
        ['won' => false, 'on_play' => false, 'started_at' => now()->subMinutes(40)],
    ])->map(function (array $data) use ($match, $local, $opponent) {
        $game = Game::factory()->for($match, 'match')->create([
            'won' => $data['won'],
            'started_at' => $data['started_at'],
            'ended_at' => $data['started_at']->copy()->addMinutes(15),
        ]);

        $game->players()->attach($local->id, [
            'is_local' => true,
            'on_play' => $data['on_play'],
            'starting_hand_size' => 7,
            'instance_id' => 0,
            'deck_json' => [],
            'mulligan_count' => 0,
        ]);
        $game->players()->attach($opponent->id, [
            'is_local' => false,
            'on_play' => ! $data['on_play'],
            'starting_hand_size' => 7,
            'instance_id' => 1,
            'deck_json' => [],
            'mulligan_count' => 0,
        ]);

        return $game;
    });

    return compact('match', 'version', 'games', 'local', 'opponent', 'cards');
}

/*
|--------------------------------------------------------------------------
| Shared pipeline and sync helpers
|--------------------------------------------------------------------------
|
| Used by more than one test file, so they live here: under --parallel a
| file can run in a worker that never loaded the file a helper was in.
|
*/

function createPipelineLogEvent(array $attributes = []): LogEvent
{
    return LogEvent::create(array_merge([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => rand(0, 999999),
        'byte_offset_end' => rand(1000000, 9999999),
        'timestamp' => now(),
        'level' => 'Info',
        'category' => 'Test',
        'context' => 'TestContext',
        'raw_text' => 'test log line',
        'ingested_at' => now(),
        'logged_at' => now(),
        'processed_at' => null,
    ], $attributes));
}

function mockMtgoManager(): void
{
    $tempDir = sys_get_temp_dir().'/mtgo_test_'.uniqid();
    @mkdir($tempDir, 0755, true);

    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn($tempDir);

    app()->instance('mtgo', $mock);
}

/**
 * A full 2-game match graph: a deck + version, a league link, two games,
 * one game_player row per game per side, two timelines, four card stats,
 * and two match_archetypes rows (one per side, distinct uuids). Creating
 * children bumps the parent's updated_at through the $touches cascade,
 * which is expected and not worked around here.
 */
function syncTestMatch(array $overrides = []): MtgoMatch
{
    // Matches only sync when their deck is enabled for cloud sync, so the
    // shared fixture is enabled by default; a test that wants the gated
    // case turns the flag off explicitly.
    $deck = Deck::factory()->create(['cloud_sync_enabled' => true]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create();

    $match = MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'result' => '2-1',
    ], $overrides));

    $local = Player::firstOrCreate(['username' => 'local_player'], ['is_player' => true]);
    $opponent = Player::firstOrCreate(['username' => 'opp_'.$match->token]);

    foreach ([1, 2] as $number) {
        $game = Game::factory()->create([
            'match_id' => $match->id,
            'mtgo_id' => 'game-'.$match->token.'-'.$number,
            'won' => $number === 1,
            'turn_count' => 7 + $number,
        ]);

        $game->players()->attach($local->id, [
            'is_local' => true,
            'on_play' => $number === 1,
            'starting_hand_size' => 7,
            'mulligan_count' => 0,
            'dice_roll' => 5,
            'deck_json' => null,
            'instance_id' => fake()->randomNumber(6),
        ]);
        $game->players()->attach($opponent->id, [
            'is_local' => false,
            'on_play' => $number !== 1,
            'starting_hand_size' => 7,
            'mulligan_count' => 1,
            'dice_roll' => 2,
            'deck_json' => null,
            'instance_id' => fake()->randomNumber(6),
        ]);

        GameTimeline::create(['game_id' => $game->id, 'timestamp' => now(), 'content' => ['turn' => $number]]);

        CardGameStat::create([
            'oracle_id' => 'oracle-'.$number.'-mine',
            'game_id' => $game->id,
            'deck_version_id' => $version->id,
            'quantity' => 4,
            'won' => true,
            'opponent' => false,
        ]);
        CardGameStat::create([
            'oracle_id' => 'oracle-'.$number.'-theirs',
            'game_id' => $game->id,
            'deck_version_id' => $version->id,
            'quantity' => 2,
            'won' => false,
            'opponent' => true,
        ]);
    }

    $playerArchetype = Archetype::factory()->create();
    $opponentArchetype = Archetype::factory()->create();

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $playerArchetype->id,
        'player_id' => $local->id,
        'confidence' => 1.0,
    ]);
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $opponentArchetype->id,
        'player_id' => $opponent->id,
        'confidence' => 0.5,
    ]);

    return $match->fresh();
}

/**
 * Two leagues sharing a token but with different started_at values, the
 * case (token, started_at) uniqueness exists to cover.
 *
 * @return array{0: League, 1: League}
 */
function syncTestLeaguePair(): array
{
    $token = (string) Str::uuid();

    $a = League::factory()->create(['token' => $token, 'started_at' => now()->subDays(2)]);
    $b = League::factory()->create(['token' => $token, 'started_at' => now()]);

    return [$a->fresh(), $b->fresh()];
}

function syncTestDeck(): Deck
{
    $deck = Deck::factory()->create(['original_name' => 'Original Name']);
    DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => now()]);

    return $deck->fresh();
}

/**
 * Fakes the three sync endpoints. $manifestByType maps a type to a list of
 * response bodies, consumed in call order and holding on the last one once
 * exhausted (so a single-entry list answers every manifest call for that
 * type, partial chunks included); a type absent from the map gets the
 * empty default on every call. $uploadResponse/$fetchResponse answer every
 * call to their endpoint.
 *
 * @param  array<string, list<array<string, mixed>>>  $manifestByType
 */
function fakeSync(array $manifestByType = [], mixed $uploadResponse = null, mixed $fetchResponse = null): void
{
    // Http::fake() merges new stubs onto the existing stub list rather than
    // replacing it (first-registered match wins), so a second call within
    // the same test would otherwise leave the first call's responses (and
    // its now-stale request-count expectations) shadowing this one. Reset
    // the same way the suite-global beforeEach does.
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    // slots is null rather than an empty ledger on purpose: an empty ledger
    // is a real instruction to turn every deck off, which would gate every
    // match and league out of the tests that do not care about slots. A test
    // that does care passes its own ledger through $manifestByType.
    $default = ['upload' => [], 'download' => [], 'tombstones' => [], 'slots' => null];
    $cursors = [];

    Http::fake([
        '*/api/sync/manifest' => function ($request) use ($manifestByType, $default, &$cursors) {
            $type = $request['type'];
            $responses = $manifestByType[$type] ?? [$default];
            $index = $cursors[$type] ?? 0;
            $cursors[$type] = $index + 1;

            return Http::response($responses[$index] ?? $responses[array_key_last($responses)]);
        },
        '*/api/sync/blobs/fetch' => $fetchResponse ?? Http::response(['blobs' => []]),
        '*/api/sync/blobs' => $uploadResponse ?? Http::response(['stored' => [], 'rejected' => []]),
    ]);
}

/**
 * Every manifest request sent, in send order, for one type.
 *
 * @return Collection<int, Request>
 */
function manifestRequestsFor(string $type)
{
    return collect(Http::recorded(fn ($request) => $request->url() === 'https://mymtgo.com/api/sync/manifest' && $request['type'] === $type))
        ->map(fn (array $pair) => $pair[0])
        ->values();
}

/**
 * Deletes a match's whole local graph so a later pull genuinely recreates
 * it rather than refreshing an already-present row. Mirrors
 * BundleRoundTripTest's syncRoundTrip cleanup.
 */
function wipeMatchLocally(MtgoMatch $match): void
{
    $match->games()->each(function ($game) {
        DB::table('game_player')->where('game_id', $game->id)->delete();
        $game->timeline()->delete();
        CardGameStat::where('game_id', $game->id)->delete();
    });
    $match->archetypes()->delete();
    $match->games()->delete();
    $match->delete();
}
