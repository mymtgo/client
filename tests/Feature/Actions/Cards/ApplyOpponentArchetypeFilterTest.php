<?php

use App\Actions\Cards\ApplyOpponentArchetypeFilter;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Build a completed game whose opponent carries $archetype, with one
 * card_game_stats row for $oracleId. Returns the game id.
 */
function statGameVsArchetype(DeckVersion $version, Archetype $archetype, string $oracleId, string $token): int
{
    $match = MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::Complete,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $opponent = Player::create(['username' => 'opp-'.$token]);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-'.$token, 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
    ]);

    CardGameStat::create([
        'oracle_id' => $oracleId,
        'game_id' => $game->id,
        'deck_version_id' => $version->id,
        'quantity' => 2,
        'won' => true,
        'opponent' => false,
    ]);

    return $game->id;
}

it('keeps only rows from games against the given archetype', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::create(['deck_id' => $deck->id, 'signature' => '', 'modified_at' => now()]);

    $wanted = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $other = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern']);

    $keptGame = statGameVsArchetype($version, $wanted, 'o-keep', 'tok-keep');
    statGameVsArchetype($version, $other, 'o-drop', 'tok-drop');

    $query = DB::table('card_game_stats as cgs')->where('cgs.opponent', false);
    ApplyOpponentArchetypeFilter::to($query, $wanted->id);

    $rows = $query->pluck('cgs.game_id')->all();

    expect($rows)->toBe([$keptGame]);
});
