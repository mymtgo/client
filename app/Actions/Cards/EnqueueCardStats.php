<?php

namespace App\Actions\Cards;

use App\Enums\MatchState;
use App\Models\CardStatShipQueue;
use App\Models\Game;

class EnqueueCardStats
{
    /**
     * Find eligible games not yet queued and insert per-game payloads.
     * Idempotent: unique constraint on game_id + insertOrIgnore.
     */
    public static function run(int $limit = 500): int
    {
        $games = Game::query()
            ->whereNotNull('won')
            ->whereDoesntHave('shipQueueEntry')
            ->whereHas('match', fn ($q) => $q
                ->where('state', MatchState::Complete)
                ->whereNotNull('deck_version_id')
                ->whereHas('archetypes'))
            ->whereHas('cardGameStats', fn ($q) => $q->where('opponent', false))
            ->with([
                'players',
                'match',
                'match.games',
                'match.archetypes.archetype',
                'match.opponentArchetypes.archetype',
                'cardGameStats' => fn ($q) => $q->where('opponent', false),
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $inserted = 0;

        foreach ($games as $game) {
            $payload = BuildCardStatsGamePayload::run($game);

            if ($payload === null) {
                continue;
            }

            $result = CardStatShipQueue::query()->insertOrIgnore([
                'game_id' => $game->id,
                'match_id' => $game->match_id,
                'payload' => json_encode($payload),
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted += $result;
        }

        return $inserted;
    }
}
