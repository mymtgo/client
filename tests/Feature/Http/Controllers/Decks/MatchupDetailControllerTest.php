<?php

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns matchup detail as json', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    $opponent = Player::firstOrCreate(['username' => 'TestOpponent']);

    $match = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now(),
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => true,
    ]);

    $game->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
        'mulligan_count' => 0,
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
    ]);

    $this->getJson(route('decks.matchup-detail', ['deck' => $deck->id, 'archetype' => $archetype->id]))
        ->assertOk()
        ->assertJsonStructure([
            'matchWinrate',
            'gameWinrate',
            'matchRecord',
            'gameRecord',
            'matches',
            'otpWinrate',
            'otpRecord',
            'otdWinrate',
            'otdRecord',
            'avgTurns',
            'avgMulligans',
            'onPlayRate',
            'bestCards',
            'worstCards',
            'matchHistory',
        ])
        ->assertJson([
            'matchWinrate' => 100,
            'matches' => 1,
        ]);
});

it('accepts timeframe query parameter', function () {
    $deck = Deck::factory()->create();
    DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    $this->getJson(route('decks.matchup-detail', ['deck' => $deck->id, 'archetype' => $archetype->id]).'?timeframe=week')
        ->assertOk()
        ->assertJson([
            'matches' => 0,
        ]);
});
