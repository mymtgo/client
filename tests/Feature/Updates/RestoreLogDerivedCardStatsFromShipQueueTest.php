<?php

use App\Jobs\RestoreLogDerivedCardStats;
use App\Models\CardStatShipQueue;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Updates\RestoreLogDerivedCardStatsFromShipQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeZeroedStatRow(Game $game, DeckVersion $deckVersion, string $oracleId, array $overrides = []): void
{
    DB::table('card_game_stats')->insert(array_merge([
        'oracle_id' => $oracleId,
        'game_id' => $game->id,
        'deck_version_id' => $deckVersion->id,
        'quantity' => 4,
        'kept' => 1,
        'seen' => 2,
        'cast' => 0,
        'played' => 0,
        'kicked' => 0,
        'flashback' => 0,
        'madness' => 0,
        'evoked' => 0,
        'activated' => 0,
        'won' => true,
        'is_postboard' => false,
        'sided_out' => false,
        'sided_in' => false,
        'opponent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function makeShipQueueRow(Game $game, array $cards, string $status = 'sent'): void
{
    CardStatShipQueue::query()->insert([
        'game_id' => $game->id,
        'match_id' => $game->match_id,
        'payload' => json_encode([
            'player_archetype_uuid' => 'uuid-a',
            'opponent_archetype_uuid' => null,
            'format' => 'modern',
            'won' => true,
            'on_play' => true,
            'is_postboard' => false,
            'played_on' => '2026-08-01',
            'cards' => $cards,
        ]),
        'status' => $status,
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('restores zeroed log-derived counters from the frozen ship-queue payload', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(['deck_version_id' => $deckVersion->id, 'state' => 'complete']);
    $game = Game::factory()->for($match, 'match')->create(['won' => true, 'started_at' => now()]);

    makeZeroedStatRow($game, $deckVersion, 'oracle-whir');
    makeShipQueueRow($game, [[
        'oracle_id' => 'oracle-whir',
        'quantity' => 4, 'kept' => 1, 'seen' => 2,
        'cast' => 3, 'played' => 0, 'kicked' => 1,
        'flashback' => 0, 'madness' => 0, 'evoked' => 0, 'activated' => 2,
        'sided_in' => false, 'sided_out' => false,
        'pregame_revealed' => false, 'pregame_played' => false,
    ]]);

    (new RestoreLogDerivedCardStats)->handle();

    $stat = DB::table('card_game_stats')->where('game_id', $game->id)->where('oracle_id', 'oracle-whir')->first();
    expect($stat->cast)->toBe(3);
    expect($stat->kicked)->toBe(1);
    expect($stat->activated)->toBe(2);
    // Snapshot-derived values stay as the regen computed them.
    expect($stat->kept)->toBe(1);
    expect($stat->seen)->toBe(2);
});

it('leaves games alone when their recomputed counters are non-zero', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(['deck_version_id' => $deckVersion->id, 'state' => 'complete']);
    $game = Game::factory()->for($match, 'match')->create(['won' => true, 'started_at' => now()]);

    // Regen had a real log source: cast=1 survived. Payload says 3 (older data).
    makeZeroedStatRow($game, $deckVersion, 'oracle-whir', ['cast' => 1]);
    makeShipQueueRow($game, [[
        'oracle_id' => 'oracle-whir',
        'quantity' => 4, 'kept' => 1, 'seen' => 2,
        'cast' => 3, 'played' => 0, 'kicked' => 0,
        'flashback' => 0, 'madness' => 0, 'evoked' => 0, 'activated' => 0,
        'sided_in' => false, 'sided_out' => false,
        'pregame_revealed' => false, 'pregame_played' => false,
    ]]);

    (new RestoreLogDerivedCardStats)->handle();

    expect(DB::table('card_game_stats')->where('game_id', $game->id)->value('cast'))->toBe(1);
});

it('dispatches the restore behind the recompute jobs instead of running inline', function () {
    Queue::fake();

    (new RestoreLogDerivedCardStatsFromShipQueue)->run();

    Queue::assertPushedOn('default', RestoreLogDerivedCardStats::class);
});
