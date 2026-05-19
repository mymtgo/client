<?php

namespace Tests\Helpers;

use App\Models\Archetype;
use App\Models\CardGameStat;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Str;

class CardStatsTelemetryFactory
{
    /**
     * @param  array<string, mixed>  $matchOverrides
     * @param  array<int, array<string, mixed>>  $games  per-game overrides: won, on_play, started_at, cards, skipLocalArchetype
     * @return array{match: MtgoMatch, deckVersion: DeckVersion, local: Player, opponent: Player, archetype: Archetype, opponentArchetype: Archetype, games: array<int, Game>}
     */
    public static function make(array $matchOverrides = [], array $games = [], bool $withOpponentArchetype = true, bool $withLocalArchetype = true): array
    {
        $deckVersion = DeckVersion::factory()->create();
        $archetype = Archetype::factory()->create(['uuid' => (string) Str::uuid()]);
        $opponentArchetype = Archetype::factory()->create(['uuid' => (string) Str::uuid()]);

        $match = MtgoMatch::factory()->create(array_merge([
            'deck_version_id' => $deckVersion->id,
            'format' => 'CStandard',
        ], $matchOverrides));

        $local = Player::factory()->create(['username' => 'localplayer']);
        $opponent = Player::factory()->create(['username' => 'opp']);

        if ($withLocalArchetype) {
            MatchArchetype::create([
                'mtgo_match_id' => $match->id,
                'player_id' => $local->id,
                'archetype_id' => $archetype->id,
                'confidence' => 0.9,
            ]);
        }

        if ($withOpponentArchetype) {
            MatchArchetype::create([
                'mtgo_match_id' => $match->id,
                'player_id' => $opponent->id,
                'archetype_id' => $opponentArchetype->id,
                'confidence' => 0.8,
            ]);
        }

        $games = $games ?: [['won' => true, 'on_play' => true, 'cards' => [['oracle_id' => (string) Str::uuid()]]]];

        $createdGames = [];
        foreach ($games as $i => $spec) {
            $game = Game::factory()->for($match, 'match')->create([
                'won' => $spec['won'] ?? true,
                'started_at' => $spec['started_at'] ?? now()->addMinutes($i),
            ]);

            $game->players()->attach($local->id, [
                'instance_id' => 0,
                'is_local' => true,
                'on_play' => $spec['on_play'] ?? true,
            ]);
            $game->players()->attach($opponent->id, [
                'instance_id' => 1,
                'is_local' => false,
                'on_play' => false,
            ]);

            foreach ($spec['cards'] ?? [] as $cardSpec) {
                CardGameStat::create(array_merge([
                    'game_id' => $game->id,
                    'deck_version_id' => $deckVersion->id,
                    'oracle_id' => (string) Str::uuid(),
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
                    'pregame_revealed' => false,
                    'pregame_played' => false,
                    'won' => $spec['won'] ?? true,
                    'is_postboard' => $i > 0,
                    'sided_out' => false,
                    'sided_in' => false,
                    'opponent' => false,
                ], $cardSpec));
            }

            $createdGames[] = $game;
        }

        return [
            'match' => $match,
            'deckVersion' => $deckVersion,
            'local' => $local,
            'opponent' => $opponent,
            'archetype' => $archetype,
            'opponentArchetype' => $opponentArchetype,
            'games' => $createdGames,
        ];
    }
}
