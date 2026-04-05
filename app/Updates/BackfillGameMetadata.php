<?php

namespace App\Updates;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillGameMetadata extends AppUpdate
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
                $cardData = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
                $gameMeta = $cardData['game_meta'] ?? [];

                foreach ($match->games->sortBy('started_at')->values() as $index => $game) {
                    $meta = $gameMeta[$index] ?? [];

                    if (! empty($meta['turn_count'])) {
                        $game->update(['turn_count' => $meta['turn_count']]);
                    }

                    $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);
                    $opponentPlayer = $game->players->first(fn ($p) => ! $p->pivot->is_local);

                    if (! $localPlayer || ! $opponentPlayer) {
                        continue;
                    }

                    $localName = $localPlayer->username;
                    $opponentName = $opponentPlayer->username;

                    if (! empty($meta['dice_rolls']) || ! empty($meta['mulligans'])) {
                        DB::table('game_player')
                            ->where('game_id', $game->id)
                            ->where('player_id', $localPlayer->id)
                            ->update([
                                'dice_roll' => $meta['dice_rolls'][$localName] ?? null,
                                'mulligan_count' => $meta['mulligans'][$localName] ?? 0,
                            ]);

                        DB::table('game_player')
                            ->where('game_id', $game->id)
                            ->where('player_id', $opponentPlayer->id)
                            ->update([
                                'dice_roll' => $meta['dice_rolls'][$opponentName] ?? null,
                                'mulligan_count' => $meta['mulligans'][$opponentName] ?? 0,
                            ]);
                    }
                }

                $updated++;
            } catch (\Throwable $e) {
                Log::warning("BackfillGameMetadata: failed match {$match->id}: {$e->getMessage()}");
            }
        }

        Log::info("BackfillGameMetadata: updated {$updated} matches");
    }
}
