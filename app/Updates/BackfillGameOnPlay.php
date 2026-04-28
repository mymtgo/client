<?php

namespace App\Updates;

use App\Actions\Matches\ExtractGameResults;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-derive on_play pivots from decoded game logs.
 *
 * CreateGames could record on_play=false for both players when the binary
 * game log was empty during early ingestion. ResolveGameResults later
 * refreshed decoded_entries but never re-synced on_play, so the stale
 * value persisted on completed matches.
 */
class BackfillGameOnPlay extends AppUpdate
{
    public function run(): void
    {
        $matches = MtgoMatch::query()
            ->whereHas('games')
            ->with(['games.players'])
            ->get();

        $updated = 0;

        foreach ($matches as $match) {
            $gameLog = GameLog::where('match_token', $match->token)
                ->whereNotNull('decoded_entries')
                ->first();

            if (! $gameLog) {
                continue;
            }

            try {
                $players = ExtractGameResults::detectPlayers($gameLog->decoded_entries);
                $username = Mtgo::resolveUsername($players);

                if (! $username) {
                    continue;
                }

                $extracted = ExtractGameResults::run($gameLog->decoded_entries, $username);
                $gameData = $extracted['games'] ?? [];

                $games = $match->games->sortBy('started_at')->values();

                foreach ($games as $index => $game) {
                    $onPlayName = $gameData[$index]['on_play'] ?? null;

                    if ($onPlayName === null) {
                        continue;
                    }

                    $localPlayer = $game->players->first(fn ($p) => $p->username === $username);
                    $opponent = $game->players->first(fn ($p) => $p->username !== $username);

                    if (! $localPlayer || ! $opponent) {
                        continue;
                    }

                    $localOnPlay = $onPlayName === $username;
                    $opponentOnPlay = $onPlayName === $opponent->username;

                    if ((bool) $localPlayer->pivot->on_play !== $localOnPlay) {
                        DB::table('game_player')
                            ->where('game_id', $game->id)
                            ->where('player_id', $localPlayer->id)
                            ->update(['on_play' => $localOnPlay]);
                    }

                    if ((bool) $opponent->pivot->on_play !== $opponentOnPlay) {
                        DB::table('game_player')
                            ->where('game_id', $game->id)
                            ->where('player_id', $opponent->id)
                            ->update(['on_play' => $opponentOnPlay]);
                    }
                }

                $updated++;
            } catch (\Throwable $e) {
                Log::warning("BackfillGameOnPlay: failed match {$match->id}: {$e->getMessage()}");
            }
        }

        Log::info("BackfillGameOnPlay: processed {$updated} matches");
    }
}
