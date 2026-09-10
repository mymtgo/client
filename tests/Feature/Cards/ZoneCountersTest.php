<?php

use App\Actions\Cards\CountZonesByOracle;
use App\Jobs\ComputeCardGameStats;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function zones_seedLog(string $matchToken, array $entries): void
{
    $instance = LogInstance::factory()->create();

    foreach ($entries as $i => $entry) {
        $text = $entry['message'];
        $len = strlen($text);
        $bytes = array_merge(
            [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [$len, 0, 0, 0],
            array_map('ord', str_split($text))
        );

        LogEvent::factory()->create([
            'log_instance_id' => $instance->id,
            'match_token' => $matchToken,
            'event_type' => 'game_management_json',
            'timestamp' => Carbon::parse('2026-05-26 10:00:00')->addSeconds($i)->format('H:i:s'),
            'byte_offset_start' => $i * 1000,
            'raw_text' => sprintf(
                '00:00:00 [INF] (Game Management|Processing) Message: {"MatchToken":"%s","MatchID":1,"GameID":1,"MetaMessage":[%s]}',
                $matchToken,
                implode(',', $bytes)
            ),
        ]);
    }
}

/**
 * A game with the local player on instance 0, one card in the deck, and
 * however many snapshots the caller wants, in order.
 *
 * @param  list<list<array<string, mixed>>>  $snapshots
 */
function zones_game(array $snapshots, int $quantity = 4, bool $won = true): array
{
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(['deck_version_id' => $deckVersion->id, 'state' => 'complete']);
    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    Card::factory()->create(['oracle_id' => 'oracle-a', 'mtgo_id' => 1001, 'name' => 'Card A']);

    $game = Game::factory()->for($match, 'match')->create(['won' => $won, 'started_at' => now()]);

    $game->players()->attach($local->id, [
        'instance_id' => 0,
        'is_local' => true,
        'on_play' => true,
        'deck_json' => [['mtgo_id' => 1001, 'quantity' => $quantity, 'sideboard' => false]],
    ]);
    $game->players()->attach($opponent->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false]);

    foreach ($snapshots as $i => $cards) {
        GameTimeline::create([
            'game_id' => $game->id,
            'content' => [
                'Players' => [
                    ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                    ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                ],
                'Cards' => $cards,
            ],
            'timestamp' => Carbon::parse('2026-05-26 09:00:00')->addSeconds($i)->format('H:i:s'),
        ]);
    }

    return [$match, $game];
}

function zones_stat(Game $game): ?object
{
    return DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-a')
        ->where('game_id', $game->id)
        ->first();
}

it('counts each zone separately rather than collapsing them', function () {
    [$match, $game] = zones_game([
        [
            ['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
            ['Id' => 11, 'CatalogID' => 1001, 'Zone' => 'Graveyard', 'Owner' => 0, 'Controller' => 0],
        ],
        [
            ['Id' => 12, 'CatalogID' => 1001, 'Zone' => 'Battlefield', 'Owner' => 0, 'Controller' => 0],
            ['Id' => 13, 'CatalogID' => 1001, 'Zone' => 'Exile', 'Owner' => 0, 'Controller' => 0],
        ],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = zones_stat($game);
    expect($stat->hand_seen)->toBe(1);
    expect($stat->graveyard_seen)->toBe(1);
    expect($stat->battlefield_seen)->toBe(1);
    expect($stat->exile_seen)->toBe(1);
    expect((bool) $stat->has_zone_data)->toBeTrue();

    // seen is still the union, unchanged, because the funnel reads it.
    expect($stat->seen)->toBe(4);
});

it('counts a copy that went from hand to the graveyard as discarded', function () {
    [$match, $game] = zones_game([
        [['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0]],
        [['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Graveyard', 'Owner' => 0, 'Controller' => 0]],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    expect(zones_stat($game)->discarded)->toBe(1);
});

it('does not call a cast spell a discard', function () {
    // A cast spell reaches the graveyard by the same route as a discarded
    // one, so the zone walk alone cannot separate them.
    [$match, $game] = zones_game([
        [['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0]],
        [['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Graveyard', 'Owner' => 0, 'Controller' => 0]],
    ]);

    zones_seedLog($match->token, [
        ['message' => '@P@Ptestplayer joined the game.'],
        ['message' => '@P@Popponent joined the game.'],
        ['message' => '@PTurn 1: testplayer'],
        ['message' => '@Ptestplayer casts @[Card A@:1001,100:@].'],
        ['message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = zones_stat($game);
    expect($stat->cast)->toBe(1);
    expect($stat->discarded)->toBe(0);
});

it('records the turn a card was first cast on', function () {
    [$match, $game] = zones_game([
        [['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0]],
    ]);

    zones_seedLog($match->token, [
        ['message' => '@P@Ptestplayer joined the game.'],
        ['message' => '@P@Popponent joined the game.'],
        ['message' => '@PTurn 1: testplayer'],
        ['message' => '@PTurn 2: opponent'],
        ['message' => '@PTurn 3: testplayer'],
        ['message' => '@Ptestplayer casts @[Card A@:1001,100:@].'],
        ['message' => '@PTurn 5: testplayer'],
        ['message' => '@Ptestplayer casts @[Card A@:1001,100:@].'],
        ['message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    // The first cast, not the last: the question is how soon it came down.
    expect(zones_stat($game)->cast_turn)->toBe(3);
});

it('leaves the cast turn empty for a card that was never cast', function () {
    [$match, $game] = zones_game([
        [['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0]],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    expect(zones_stat($game)->cast_turn)->toBeNull();
});

it('reports no zone data for a game with no timeline', function () {
    [$match, $game] = zones_game([]);

    zones_seedLog($match->token, [
        ['message' => '@P@Ptestplayer joined the game.'],
        ['message' => '@P@Popponent joined the game.'],
        ['message' => '@PTurn 1: testplayer'],
        ['message' => '@Ptestplayer casts @[Card A@:1001,100:@].'],
        ['message' => '@Ptestplayer wins the game.'],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = zones_stat($game);

    // An imported match has a log but no snapshots. Zero hand copies here is
    // an absence of evidence, and the flag is what says so.
    expect((bool) $stat->has_zone_data)->toBeFalse();
    expect($stat->hand_seen)->toBe(0);
    expect($stat->cast)->toBe(1);
});

it('caps a zone count at the copies in the deck', function () {
    // Five instances of a four-of: an instance id changed mid-game. The count
    // is capped the same way `seen` is.
    [$match, $game] = zones_game([
        array_map(fn (int $id): array => [
            'Id' => $id, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0,
        ], [10, 11, 12, 13, 14]),
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    expect(zones_stat($game)->hand_seen)->toBe(4);
});

it('ignores cards owned by the opponent', function () {
    [$match, $game] = zones_game([
        [
            ['Id' => 10, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 0, 'Controller' => 0],
            ['Id' => 20, 'CatalogID' => 1001, 'Zone' => 'Hand', 'Owner' => 1, 'Controller' => 1],
        ],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    expect(zones_stat($game)->hand_seen)->toBe(1);
});

it('leaves the stack out of the zone split', function () {
    // A card on the stack is one being cast, and `cast` already records that.
    expect(CountZonesByOracle::ZONES)->toBe(['hand', 'graveyard', 'exile', 'battlefield']);
});

/**
 * MTGO reports a display zone and a real one. A card exiled under Ugin's
 * Labyrinth sits under the land, so Zone says Battlefield while ActualZone
 * says Exile. Counting the display zone called it a battlefield arrival,
 * which is exactly the reanimation signal it must not pollute.
 */
it('believes ActualZone over the display zone', function () {
    [$match, $game] = zones_game([
        [[
            'Id' => 10, 'CatalogID' => 1001,
            'Zone' => 'Battlefield', 'ActualZone' => 'Exile',
            'Owner' => 0, 'Controller' => 0,
        ]],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = zones_stat($game);
    expect($stat->exile_seen)->toBe(1);
    expect($stat->battlefield_seen)->toBe(0);
});

it('counts a companion in the sideboard as no zone at all', function () {
    // Zone "Companion", ActualZone "Sideboard": neither is one of ours, and
    // a companion sitting outside the game has not been drawn or played.
    [$match, $game] = zones_game([
        [[
            'Id' => 10, 'CatalogID' => 1001,
            'Zone' => 'Companion', 'ActualZone' => 'Sideboard',
            'Owner' => 0, 'Controller' => 0,
        ]],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stat = zones_stat($game);
    expect($stat->hand_seen)->toBe(0);
    expect($stat->battlefield_seen)->toBe(0);
    expect($stat->exile_seen)->toBe(0);
});
