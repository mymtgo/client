<?php

namespace App\Actions\Cards;

use App\Models\Game;
use Illuminate\Support\Facades\Log;

class BuildCardStatsGamePayload
{
    /**
     * Build the per-game payload for POST /api/card-stats/report.
     * Returns null when required fields cannot be resolved.
     *
     * @return array<string, mixed>|null
     */
    public static function run(Game $game): ?array
    {
        $match = $game->match;

        if (! $match) {
            return null;
        }

        if ($game->local_instance === null && $game->localDeck() === null) {
            return self::skip($game, 'no local player');
        }

        $playerArchetypeUuid = $match->archetypes
            ->first(fn ($a) => ! $a->is_opponent)
            ?->archetype?->uuid;

        if (! $playerArchetypeUuid) {
            return self::skip($game, 'no player archetype uuid');
        }

        $opponentArchetypeUuid = $match->opponentArchetypes
            ->sortBy('id')
            ->first()
            ?->archetype?->uuid;

        $format = $match->format;

        if (! $format) {
            return self::skip($game, 'no match format');
        }

        $playedOn = ($game->started_at ?? $match->started_at)?->toDateString();

        if (! $playedOn) {
            return self::skip($game, 'no played_on date');
        }

        $sortedGames = $match->games->sortBy('started_at')->values();
        $gameIndex = $sortedGames->search(fn ($g) => $g->id === $game->id);
        $isPostboard = $gameIndex > 0;

        $cards = $game->cardGameStats
            ->where('opponent', false)
            ->map(fn ($stat) => [
                'oracle_id' => $stat->oracle_id,
                'quantity' => (int) $stat->quantity,
                'kept' => (int) $stat->kept,
                'seen' => (int) $stat->seen,
                'cast' => (int) $stat->cast,
                'played' => (int) $stat->played,
                'kicked' => (int) $stat->kicked,
                'flashback' => (int) $stat->flashback,
                'madness' => (int) $stat->madness,
                'evoked' => (int) $stat->evoked,
                'activated' => (int) $stat->activated,
                'sided_in' => (bool) ($stat->sided_in ?? false),
                'sided_out' => (bool) $stat->sided_out,
                'pregame_revealed' => (bool) $stat->pregame_revealed,
                'pregame_played' => (bool) $stat->pregame_played,
            ])
            ->values()
            ->all();

        if (empty($cards)) {
            return self::skip($game, 'no player-side card stats');
        }

        return [
            'player_archetype_uuid' => $playerArchetypeUuid,
            'opponent_archetype_uuid' => $opponentArchetypeUuid,
            'format' => $format,
            'won' => (bool) $game->won,
            'on_play' => (bool) $game->local_on_play,
            'is_postboard' => $isPostboard,
            'played_on' => $playedOn,
            'cards' => $cards,
        ];
    }

    private static function skip(Game $game, string $reason): ?array
    {
        Log::warning('BuildCardStatsGamePayload: skipping game', [
            'game_id' => $game->id,
            'match_id' => $game->match_id,
            'reason' => $reason,
        ]);

        return null;
    }
}
