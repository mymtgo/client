<?php

use App\Jobs\ComputeCardGameStats;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function ccgs_seedLogEntries(string $matchToken, array $entries): void
{
    $instance = LogInstance::factory()->create();
    foreach ($entries as $i => $entry) {
        $text = $entry['message'];
        $textBytes = array_map('ord', str_split($text));
        $len = strlen($text);
        $bytes = array_merge(
            [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [$len, 0, 0, 0],
            $textBytes
        );
        LogEvent::factory()->create([
            'log_instance_id' => $instance->id,
            'match_token' => $matchToken,
            'event_type' => 'game_management_json',
            'timestamp' => Carbon::parse($entry['timestamp'] ?? '2026-05-26 10:00:00')->addSeconds($i)->format('H:i:s'),
            'byte_offset_start' => $i * 1000,
            'raw_text' => sprintf(
                '00:00:00 [INF] (Game Management|Processing) Message: {"MatchToken":"%s","MatchID":1,"GameID":1,"MetaMessage":[%s]}',
                $matchToken,
                implode(',', $bytes)
            ),
        ]);
    }
}

function createMatchWithGames(array $overrides = []): array
{
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
    ], $overrides));

    $localPlayer = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    return [$match, $deckVersion, $localPlayer, $opponent];
}

function attachPlayers(Game $game, Player $local, Player $opponent, int $localInstanceId = 0, int $opponentInstanceId = 1, array $deckJson = []): void
{
    $game->players()->attach($local->id, [
        'instance_id' => $localInstanceId,
        'is_local' => true,
        'on_play' => true,
        'deck_json' => $deckJson,
    ]);
    $game->players()->attach($opponent->id, [
        'instance_id' => $opponentInstanceId,
        'is_local' => false,
        'on_play' => false,
    ]);
}

function createTimeline(Game $game, array $cards): void
{
    GameTimeline::create([
        'game_id' => $game->id,
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => $cards,
        ],
        'timestamp' => '09:00:00',
    ]);
}

it('detects sided out cards by comparing maindeck quantities between games', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    // Card A: 4 in maindeck game 1, 2 in maindeck game 2 (sided 2 out)
    // Card B: 0 in maindeck game 1 (sideboard), 2 in maindeck game 2 (sided in)
    $cardA = Card::factory()->create(['oracle_id' => 'oracle-a', 'mtgo_id' => 1001, 'name' => 'Card A']);
    $cardB = Card::factory()->create(['oracle_id' => 'oracle-b', 'mtgo_id' => 1002, 'name' => 'Card B']);

    // Game 1: Card A x4 maindeck, Card B x2 sideboard
    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 1002, 'quantity' => 2, 'sideboard' => true],
    ]);
    createTimeline($game1, [
        ['Id' => 10, 'CatalogID' => 1002, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
        ['Id' => 11, 'CatalogID' => 1002, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
    ]);

    // Game 2: Card A x2 maindeck + x2 sideboard, Card B x2 maindeck (sided in)
    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 2, 'sideboard' => false],
        ['mtgo_id' => 1001, 'quantity' => 2, 'sideboard' => true],
        ['mtgo_id' => 1002, 'quantity' => 2, 'sideboard' => false],
    ]);
    createTimeline($game2, [
        ['Id' => 20, 'CatalogID' => 1001, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
        ['Id' => 21, 'CatalogID' => 1001, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stats = DB::table('card_game_stats')
        ->orderBy('oracle_id')
        ->orderBy('game_id')
        ->get();

    // Game 1: Card A in maindeck (not sided out), Card B not in maindeck (no row)
    $g1CardA = $stats->where('oracle_id', 'oracle-a')->where('game_id', $game1->id)->first();
    expect($g1CardA)->not->toBeNull();
    expect($g1CardA->quantity)->toBe(4);
    expect((bool) $g1CardA->is_postboard)->toBeFalse();
    expect((bool) $g1CardA->sided_out)->toBeFalse();
    expect((bool) $g1CardA->sided_in)->toBeFalse();

    $g1CardB = $stats->where('oracle_id', 'oracle-b')->where('game_id', $game1->id)->first();
    expect($g1CardB)->toBeNull(); // sideboard-only in game 1, no stats row

    // Game 2: Card A sided out (4 -> 2), Card B sided in (0 -> 2)
    $g2CardA = $stats->where('oracle_id', 'oracle-a')->where('game_id', $game2->id)->first();
    expect($g2CardA)->not->toBeNull();
    expect($g2CardA->quantity)->toBe(2);
    expect((bool) $g2CardA->is_postboard)->toBeTrue();
    expect((bool) $g2CardA->sided_out)->toBeTrue();
    expect((bool) $g2CardA->sided_in)->toBeFalse();

    $g2CardB = $stats->where('oracle_id', 'oracle-b')->where('game_id', $game2->id)->first();
    expect($g2CardB)->not->toBeNull();
    expect($g2CardB->quantity)->toBe(2);
    expect((bool) $g2CardB->is_postboard)->toBeTrue();
    expect((bool) $g2CardB->sided_out)->toBeFalse();
    expect((bool) $g2CardB->sided_in)->toBeTrue(); // sided IN from sideboard
});

it('skips sided_in/sided_out detection for imported matches because deck_json is seen-only', function () {
    $deckVersion = DeckVersion::factory()->create([
        'signature' => base64_encode('oracle-main:4:false|oracle-sb:2:true'),
    ]);
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
        'imported' => true,
    ]);
    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    Card::factory()->create(['oracle_id' => 'oracle-main', 'mtgo_id' => 3001, 'name' => 'Main Card']);

    // Imported g1 deck_json under-reports — only Main was "seen", appears at qty 1
    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: [
        ['mtgo_id' => 3001, 'quantity' => 1, 'sideboard' => false],
    ]);

    // Imported g2: Main card seen at qty 4 — would falsely trigger sided_in if compared
    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: [
        ['mtgo_id' => 3001, 'quantity' => 4, 'sideboard' => false],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $g2Main = DB::table('card_game_stats')
        ->where('opponent', false)
        ->where('oracle_id', 'oracle-main')
        ->where('game_id', $game2->id)
        ->first();

    expect($g2Main)->not->toBeNull();
    expect((bool) $g2Main->sided_in)->toBeFalse();
    expect((bool) $g2Main->sided_out)->toBeFalse();
});

it('emits zero-quantity rows for sideboard cards that stayed in sideboard', function () {
    $deckVersion = DeckVersion::factory()->create([
        'signature' => base64_encode('oracle-main:4:false|oracle-sb:2:true'),
    ]);
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
    ]);
    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    Card::factory()->create(['oracle_id' => 'oracle-main', 'mtgo_id' => 2001, 'name' => 'Main Card']);
    Card::factory()->create(['oracle_id' => 'oracle-sb', 'mtgo_id' => 2002, 'name' => 'SB Card']);

    // Game 1 (preboard): 4x main, 2x SB stays in sideboard
    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: [
        ['mtgo_id' => 2001, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 2002, 'quantity' => 2, 'sideboard' => true],
    ]);

    // Game 2 (postboard): SB card NOT sided in (still in sideboard)
    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: [
        ['mtgo_id' => 2001, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 2002, 'quantity' => 2, 'sideboard' => true],
    ]);

    // Game 3 (postboard): SB card sided in
    $game3 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now()->addMinutes(20),
    ]);
    attachPlayers($game3, $local, $opponent, deckJson: [
        ['mtgo_id' => 2001, 'quantity' => 2, 'sideboard' => false],
        ['mtgo_id' => 2002, 'quantity' => 2, 'sideboard' => false],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stats = DB::table('card_game_stats')->where('opponent', false)->get();

    // Game 1: no row for SB card (preboard, sb-only)
    $g1Sb = $stats->where('oracle_id', 'oracle-sb')->where('game_id', $game1->id)->first();
    expect($g1Sb)->toBeNull();

    // Game 2: zero-qty postboard row, NOT sided in
    $g2Sb = $stats->where('oracle_id', 'oracle-sb')->where('game_id', $game2->id)->first();
    expect($g2Sb)->not->toBeNull();
    expect($g2Sb->quantity)->toBe(0);
    expect((bool) $g2Sb->is_postboard)->toBeTrue();
    expect((bool) $g2Sb->sided_in)->toBeFalse();
    expect((bool) $g2Sb->sided_out)->toBeFalse();

    // Game 3: sided in
    $g3Sb = $stats->where('oracle_id', 'oracle-sb')->where('game_id', $game3->id)->first();
    expect($g3Sb)->not->toBeNull();
    expect($g3Sb->quantity)->toBe(2);
    expect((bool) $g3Sb->sided_in)->toBeTrue();

    // Aggregate sanity: SB In % denominator = 2 postboard games, sided_in once = 50%
    $sbAgg = $stats->where('oracle_id', 'oracle-sb');
    expect($sbAgg->where('is_postboard', 1)->count())->toBe(2);
    expect($sbAgg->where('sided_in', 1)->count())->toBe(1);
});

it('counts multiple casts of the same card instance via zone transitions', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $card = Card::factory()->create(['oracle_id' => 'oracle-a', 'mtgo_id' => 1001, 'name' => 'Card A']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 1, 'sideboard' => false],
    ]);

    // Snapshot 1: Card in Hand
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:00:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 59, 'HandCount' => 1, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 0, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    // Snapshot 2: Card cast (on Stack) — cast #1
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:01:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 59, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 0, 'Zone' => 'Stack'],
            ],
        ],
    ]);

    // Snapshot 3: Card resolves to Battlefield
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:02:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 59, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 0, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    // Snapshot 4: Card bounced back to Hand
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:03:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 59, 'HandCount' => 1, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 0, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    // Snapshot 5: Card cast again (on Stack) — cast #2
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:04:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 59, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 0, 'Zone' => 'Stack'],
            ],
        ],
    ]);

    // Snapshot 6: Resolves to Battlefield again
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:05:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 59, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 0, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T09:01:00+00:00', 'message' => '@Ptestplayer casts @[Card A@:2002,101:@].'],
        ['timestamp' => '2026-01-01T09:04:00+00:00', 'message' => '@Ptestplayer casts @[Card A@:2002,101:@].'],
        ['timestamp' => '2026-01-01T09:05:00+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-a')
        ->where('game_id', $game->id)
        ->first();

    // Card was cast twice (Hand→Stack twice), should count 2 not 1
    expect($stat->cast)->toBe(2);
    // Card was seen in Hand, Stack, Battlefield — still 1 unique instance
    expect($stat->seen)->toBe(1);
});

it('does not mark cards as sided out when deck is unchanged between games', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $card = Card::factory()->create(['oracle_id' => 'oracle-c', 'mtgo_id' => 2001, 'name' => 'Card C']);

    // Same deck in both games (no sideboarding)
    $deckJson = [
        ['mtgo_id' => 2001, 'quantity' => 4, 'sideboard' => false],
    ];

    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: $deckJson);
    createTimeline($game1, []);

    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: $deckJson);
    createTimeline($game2, []);

    (new ComputeCardGameStats($match->id))->handle();

    $g2Stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-c')
        ->where('game_id', $game2->id)
        ->first();

    expect($g2Stat)->not->toBeNull();
    expect((bool) $g2Stat->sided_out)->toBeFalse();
    expect((bool) $g2Stat->sided_in)->toBeFalse();
    expect($g2Stat->quantity)->toBe(4);
});

it('creates a sided_out row for cards completely moved to sideboard in postboard games', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    // Card D: 3 in maindeck game 1, entirely sideboard in game 2
    $card = Card::factory()->create(['oracle_id' => 'oracle-d', 'mtgo_id' => 3001, 'name' => 'Card D']);

    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: [
        ['mtgo_id' => 3001, 'quantity' => 3, 'sideboard' => false],
    ]);
    createTimeline($game1, []);

    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: [
        ['mtgo_id' => 3001, 'quantity' => 3, 'sideboard' => true],
    ]);
    createTimeline($game2, []);

    (new ComputeCardGameStats($match->id))->handle();

    // Game 1: normal maindeck row
    $g1Stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-d')
        ->where('game_id', $game1->id)
        ->first();

    expect($g1Stat)->not->toBeNull();
    expect($g1Stat->quantity)->toBe(3);
    expect((bool) $g1Stat->sided_out)->toBeFalse();

    // Game 2: completely sided out — row created with quantity 0
    $g2Stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-d')
        ->where('game_id', $game2->id)
        ->first();

    expect($g2Stat)->not->toBeNull();
    expect($g2Stat->quantity)->toBe(0);
    expect((bool) $g2Stat->is_postboard)->toBeTrue();
    expect((bool) $g2Stat->sided_out)->toBeTrue();
    expect((bool) $g2Stat->sided_in)->toBeFalse();
});

it('sources entries from the decoded game log table when run with fromGameLog (regenerate path)', function () {
    // Reproduces the regenerate regression: after a match completes, its
    // log_events are pruned, so regeneration must source entries from the
    // durable GameLog.decoded_entries instead. With fromGameLog=true and NO
    // log_events present, cast must still resolve.
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $card = Card::factory()->create(['oracle_id' => 'oracle-a', 'mtgo_id' => 1001, 'name' => 'Card A']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
    ]);
    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
    ]);

    // Durable decoded game log — same {timestamp,message}[] shape the MetaMessage
    // path produces. No log_events seeded: simulates a pruned, completed match.
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/some/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
            ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Ptestplayer casts @[Card A@:2002,100:@].'],
            ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer wins the game.'],
        ],
    ]);

    expect(LogEvent::where('match_token', $match->token)->count())->toBe(0);

    (new ComputeCardGameStats($match->id, fromGameLog: true))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-a')
        ->where('game_id', $game->id)
        ->first();

    expect($stat->cast)->toBe(1);
});

it('reads cast data from game log instead of timeline zone transitions', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $card = Card::factory()->create(['oracle_id' => 'oracle-a', 'mtgo_id' => 1001, 'name' => 'Card A']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
    ]);

    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
    ]);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Ptestplayer casts @[Card A@:2002,100:@] with kicker.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-a')
        ->where('game_id', $game->id)
        ->first();

    expect($stat->cast)->toBe(1);
    expect($stat->kicked)->toBe(1);
    expect($stat->seen)->toBe(1);
});

it('flags pregame_revealed and pregame_played from game log in live pipeline', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $devourer = Card::factory()->create(['oracle_id' => 'oracle-devourer', 'mtgo_id' => 5001, 'name' => 'Devourer of Destiny']);
    $leyline = Card::factory()->create(['oracle_id' => 'oracle-leyline', 'mtgo_id' => 5002, 'name' => 'Leyline of the Guildpact']);
    $neutral = Card::factory()->create(['oracle_id' => 'oracle-neutral', 'mtgo_id' => 5003, 'name' => 'Forest']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 5001, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 5002, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 5003, 'quantity' => 20, 'sideboard' => false],
    ]);

    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 5001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
        ['Id' => 11, 'CatalogID' => 5002, 'Zone' => 'Battlefield', 'Owner' => 0, 'Controller' => 0],
        ['Id' => 12, 'CatalogID' => 5003, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
    ]);

    // mtgo_id 5001 → CatalogID 10002 (5001 << 1), 5002 → 10004
    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Popponent begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Ptestplayer begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer reveals @[Devourer of Destiny@:10002,101:@] from their opening hand.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer puts @[Leyline of the Guildpact@:10004,102:@] onto the battlefield.'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@PTurn 1: testplayer'],
        ['timestamp' => '2026-01-01T00:00:10+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stats = DB::table('card_game_stats')
        ->where('game_id', $game->id)
        ->get()
        ->keyBy('oracle_id');

    expect((bool) $stats['oracle-devourer']->pregame_revealed)->toBeTrue();
    expect((bool) $stats['oracle-devourer']->pregame_played)->toBeFalse();

    expect((bool) $stats['oracle-leyline']->pregame_revealed)->toBeFalse();
    expect((bool) $stats['oracle-leyline']->pregame_played)->toBeTrue();

    // Card that had no pregame action gets both flags false
    expect((bool) $stats['oracle-neutral']->pregame_revealed)->toBeFalse();
    expect((bool) $stats['oracle-neutral']->pregame_played)->toBeFalse();
});

it('writes pregame flags as false when game log has no pregame actions', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $card = Card::factory()->create(['oracle_id' => 'oracle-quiet', 'mtgo_id' => 6001, 'name' => 'Card Q']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 6001, 'quantity' => 4, 'sideboard' => false],
    ]);
    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 6001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
    ]);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Popponent begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Ptestplayer begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@PTurn 1: testplayer'],
        ['timestamp' => '2026-01-01T00:00:10+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-quiet')
        ->where('game_id', $game->id)
        ->first();

    expect($stat)->not->toBeNull();
    expect((bool) $stat->pregame_revealed)->toBeFalse();
    expect((bool) $stat->pregame_played)->toBeFalse();
});

it('relinks an orphaned game log and derives imported card stats on recompute', function () {
    // Production reproduction: imported matches get a random token unrelated to
    // their .dat game log, which stays orphaned (keyed by the original log token)
    // with NO log_events and NO timeline. The job must self-heal the link by
    // opponent + start time, then source signals from the decoded log — so a
    // plain recompute (no fromGameLog) recovers real stats.
    $deckVersion = DeckVersion::factory()->create([
        'signature' => base64_encode('4001:4:false'),
    ]);
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
        'imported' => true,
        'started_at' => '2026-01-01 09:00:00',
    ]);
    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    Card::factory()->create(['oracle_id' => 'oracle-mine', 'mtgo_id' => 4001, 'name' => 'Bolt']);
    Card::factory()->create(['oracle_id' => 'oracle-opp', 'mtgo_id' => 4002, 'name' => 'Counter']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => '2026-01-01 09:00:00',
    ]);
    attachPlayers($game, $local, $opponent);

    // Orphan decoded .dat log: keyed by the original log token, matchable by
    // opponent + start time. NO log_events seeded.
    GameLog::create([
        'match_token' => 'original-dat-token',
        'file_path' => '/some/path.dat',
        'first_timestamp' => '2026-01-01 09:00:08',
        'players' => ['testplayer', 'opponent'],
        'decoded_entries' => [
            ['timestamp' => '2026-01-01T09:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
            ['timestamp' => '2026-01-01T09:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
            ['timestamp' => '2026-01-01T09:00:10+00:00', 'message' => '@Ptestplayer casts @[Bolt@:8002,500:@].'],
            ['timestamp' => '2026-01-01T09:00:30+00:00', 'message' => '@Popponent casts @[Counter@:8004,600:@].'],
            ['timestamp' => '2026-01-01T09:02:00+00:00', 'message' => '@Popponent wins the game.'],
        ],
    ]);

    expect(LogEvent::where('match_token', $match->token)->count())->toBe(0);

    // No fromGameLog — mirrors the manual recompute / at-import dispatch path.
    (new ComputeCardGameStats($match->id))->handle();

    // The orphan log is now keyed to the match.
    expect(GameLog::where('match_token', $match->token)->exists())->toBeTrue();

    $localRow = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-mine')
        ->where('game_id', $game->id)
        ->where('opponent', false)
        ->first();

    expect($localRow)->not->toBeNull();
    expect($localRow->quantity)->toBe(4);
    expect($localRow->cast)->toBe(1);
    expect($localRow->seen)->toBe(1);

    $oppRow = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-opp')
        ->where('game_id', $game->id)
        ->where('opponent', true)
        ->first();

    expect($oppRow)->not->toBeNull();
    expect($oppRow->cast)->toBe(1);
    expect($oppRow->seen)->toBe(1);
});

it('processes imported-style match (no timeline) using game log and deck version cards', function () {
    // Imported matches have game logs but no timeline. Job should still produce
    // local + opp rows using game-log signals only, with seen=1 derived from
    // any signal (since we have no zone-transition data).
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $myBolt = Card::factory()->create(['oracle_id' => 'oracle-mine', 'mtgo_id' => 4001, 'name' => 'Bolt']);
    $oppCounter = Card::factory()->create(['oracle_id' => 'oracle-opp', 'mtgo_id' => 4002, 'name' => 'Counter']);

    // Hydrate the deck version's cards so the deck_json fallback resolves.
    $deckVersion->update([
        'signature' => base64_encode('4001:4:false'),
    ]);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now(),
    ]);
    // attachPlayers passes deckJson empty by default — pivot fallback will kick in
    attachPlayers($game, $local, $opponent);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T09:00:00+00:00', 'message' => '@Ptestplayer casts @[Bolt@:8002,500:@].'],
        ['timestamp' => '2026-01-01T09:00:30+00:00', 'message' => '@Popponent casts @[Counter@:8004,600:@].'],
        ['timestamp' => '2026-01-01T09:02:00+00:00', 'message' => '@Popponent wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $localRow = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-mine')
        ->where('game_id', $game->id)
        ->where('opponent', false)
        ->first();

    expect($localRow)->not->toBeNull();
    expect($localRow->quantity)->toBe(4);
    expect($localRow->cast)->toBe(1);
    expect($localRow->seen)->toBe(1); // log-only fallback

    $oppRow = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-opp')
        ->where('game_id', $game->id)
        ->where('opponent', true)
        ->first();

    expect($oppRow)->not->toBeNull();
    expect($oppRow->cast)->toBe(1);
    expect($oppRow->seen)->toBe(1); // log-only fallback
    expect($oppRow->quantity)->toBe(0);
});

it('uses deck-version snapshot quantities for imported matches instead of sparse pivot deck_json', function () {
    // Imported pivot deck_json only contains cards seen in the game log, which
    // collapses denominators to "games where the card appeared". For imported
    // matches the deck-version snapshot is the truth.
    $tendrils = Card::factory()->create(['oracle_id' => 'oracle-tendrils', 'mtgo_id' => 9001, 'name' => 'Tendrils of Agony']);
    $lotus = Card::factory()->create(['oracle_id' => 'oracle-lotus', 'mtgo_id' => 9002, 'name' => 'Lotus Petal']);
    $bolt = Card::factory()->create(['oracle_id' => 'oracle-bolt', 'mtgo_id' => 9003, 'name' => 'Lightning Bolt']);

    $deckVersion = DeckVersion::factory()->create([
        'signature' => base64_encode('9001:4:false|9002:4:false|9003:2:false'),
    ]);
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
        'imported' => true,
    ]);
    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    // Sparse pivot — only Lotus was seen this game.
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 9002, 'quantity' => 1, 'sideboard' => false],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $tendrilsRow = DB::table('card_game_stats')
        ->where('opponent', false)
        ->where('oracle_id', 'oracle-tendrils')
        ->where('game_id', $game->id)
        ->first();

    expect($tendrilsRow)->not->toBeNull();
    expect((int) $tendrilsRow->quantity)->toBe(4);

    $lotusRow = DB::table('card_game_stats')
        ->where('opponent', false)
        ->where('oracle_id', 'oracle-lotus')
        ->where('game_id', $game->id)
        ->first();

    expect($lotusRow)->not->toBeNull();
    expect((int) $lotusRow->quantity)->toBe(4);

    $boltRow = DB::table('card_game_stats')
        ->where('opponent', false)
        ->where('oracle_id', 'oracle-bolt')
        ->where('game_id', $game->id)
        ->first();

    expect($boltRow)->not->toBeNull();
    expect((int) $boltRow->quantity)->toBe(2);
});

it('writes opponent rows with cast and seen data from game log and timeline', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $oppCard = Card::factory()->create(['oracle_id' => 'oracle-opp', 'mtgo_id' => 7001, 'name' => 'Bolt']);
    $myLand = Card::factory()->create(['oracle_id' => 'oracle-mine', 'mtgo_id' => 7002, 'name' => 'Forest']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 7002, 'quantity' => 24, 'sideboard' => false],
    ]);

    // Opp's bolt seen on Stack and Graveyard. Owner=1 = opp instance.
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:00:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 200, 'CatalogID' => 7001, 'Owner' => 1, 'Zone' => 'Stack'],
            ],
        ],
    ]);
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:01:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 200, 'CatalogID' => 7001, 'Owner' => 1, 'Zone' => 'Graveyard'],
            ],
        ],
    ]);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T09:00:00+00:00', 'message' => '@Popponent casts @[Bolt@:14002,200:@].'],
        ['timestamp' => '2026-01-01T09:02:00+00:00', 'message' => '@Popponent wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $oppRow = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-opp')
        ->where('game_id', $game->id)
        ->where('opponent', true)
        ->first();

    expect($oppRow)->not->toBeNull();
    expect($oppRow->cast)->toBe(1);
    expect($oppRow->seen)->toBe(1);
    expect($oppRow->quantity)->toBe(0);
    expect($oppRow->kept)->toBe(0);
    expect((bool) $oppRow->won)->toBeFalse();
    expect((bool) $oppRow->sided_out)->toBeFalse();
    expect((bool) $oppRow->sided_in)->toBeFalse();

    // Local row for our card unchanged
    $localRow = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-mine')
        ->where('game_id', $game->id)
        ->where('opponent', false)
        ->first();

    expect($localRow)->not->toBeNull();
    expect($localRow->quantity)->toBe(24);
});

it('does not write opponent rows when no signals are present for that card', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $myCard = Card::factory()->create(['oracle_id' => 'oracle-mine', 'mtgo_id' => 8001, 'name' => 'Forest']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 8001, 'quantity' => 24, 'sideboard' => false],
    ]);
    createTimeline($game, []);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T09:02:00+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $oppRows = DB::table('card_game_stats')
        ->where('game_id', $game->id)
        ->where('opponent', true)
        ->get();

    expect($oppRows)->toHaveCount(0);
});

it('allows local and opponent rows for the same oracle in the same game', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    // Both players on Bolt — local has it in deck, opp casts it
    $bolt = Card::factory()->create(['oracle_id' => 'oracle-bolt', 'mtgo_id' => 9001, 'name' => 'Bolt']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 9001, 'quantity' => 4, 'sideboard' => false],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '09:00:00',
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => [
                ['Id' => 300, 'CatalogID' => 9001, 'Owner' => 1, 'Zone' => 'Stack'],
            ],
        ],
    ]);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T09:00:00+00:00', 'message' => '@Popponent casts @[Bolt@:18002,300:@].'],
        ['timestamp' => '2026-01-01T09:02:00+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $rows = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-bolt')
        ->where('game_id', $game->id)
        ->get();

    expect($rows)->toHaveCount(2);

    $localRow = $rows->firstWhere('opponent', 0) ?? $rows->firstWhere('opponent', false);
    $oppRow = $rows->firstWhere('opponent', 1) ?? $rows->firstWhere('opponent', true);

    expect($localRow)->not->toBeNull();
    expect($localRow->quantity)->toBe(4);

    expect($oppRow)->not->toBeNull();
    expect($oppRow->cast)->toBe(1);
});

it('tracks land plays separately from casts in live pipeline', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $land = Card::factory()->create(['oracle_id' => 'oracle-land', 'mtgo_id' => 2001, 'name' => 'Forest']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 2001, 'quantity' => 4, 'sideboard' => false],
    ]);

    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 2001, 'Zone' => 'Battlefield', 'Owner' => 0, 'Controller' => 0],
    ]);

    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Ptestplayer plays @[Forest@:4002,100:@].'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-land')
        ->where('game_id', $game->id)
        ->first();

    expect($stat->cast)->toBe(0);
    expect($stat->played)->toBe(1);
});

it('counts casts logged under a different printing than the registered deck card', function () {
    // MTGO can log a cast under a different printing's CatalogID than the one
    // registered in the deck — most visibly with warp casts, where the warp
    // variant carries its own CatalogID. The deck and timeline use the base
    // printing, but the cast line uses the warp printing. Both printings share
    // one oracle id, so the cast must still be attributed to the deck card.
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    // Two printings of the same card, same oracle.
    Card::factory()->create(['oracle_id' => 'oracle-qr', 'mtgo_id' => 1001, 'name' => 'Quantum Riddler']);
    Card::factory()->create(['oracle_id' => 'oracle-qr', 'mtgo_id' => 1002, 'name' => 'Quantum Riddler']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);

    // Deck registered under the base printing only.
    attachPlayers($game, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
    ]);

    // Timeline (drives SEEN) carries the base printing — this already resolves.
    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
    ]);

    // Cast is logged under the warp printing (1002 << 1 = 2004), NOT 1001.
    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Ptestplayer casts @[Quantum Riddler@:2004,100:@] by paying {1U} with warp.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-qr')
        ->where('game_id', $game->id)
        ->first();

    // SEEN already worked via the base printing; the cast under the warp
    // printing must resolve to the same oracle instead of being dropped.
    expect($stat->seen)->toBe(1);
    expect($stat->cast)->toBe(1);
});

it('counts opponent casts logged under a multi-face card face CatalogID', function () {
    // Multi-face cards (adventure, omen, disturb DFCs) log casts under the
    // face's own CatalogID, while snapshots and the Cards table carry only the
    // parent printing ("Brazen Borrower // Petty Theft"). The face id has no
    // Card row, so the cast must resolve to the parent's oracle via the face
    // name instead of being silently dropped.
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    Card::factory()->create([
        'oracle_id' => 'oracle-bb',
        'mtgo_id' => 2000,
        'name' => 'Brazen Borrower // Petty Theft',
    ]);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game, $local, $opponent);

    // Timeline (drives SEEN) carries the parent printing owned by the opponent.
    createTimeline($game, [
        ['Id' => 10, 'CatalogID' => 2000, 'Zone' => 'Stack', 'Owner' => 1, 'Controller' => 1],
    ]);

    // Cast is logged under the face's CatalogID (2002 << 1 = 4004), which has
    // no Card row of its own.
    ccgs_seedLogEntries($match->token, [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Popponent casts @[Petty Theft@:4004,100:@].'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-bb')
        ->where('game_id', $game->id)
        ->where('opponent', true)
        ->first();

    expect($stat)->not->toBeNull();
    expect($stat->cast)->toBe(1);
});
