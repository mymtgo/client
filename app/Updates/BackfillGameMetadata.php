<?php

namespace App\Updates;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class BackfillGameMetadata extends AppUpdate
{
    public function run(): void
    {
        $matches = MtgoMatch::query()
            ->whereHas('games')
            ->with(['games', 'account', 'opponent'])
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

                $localName = $match->account?->username;
                $opponentName = $match->opponent?->username;

                foreach ($match->games->sortBy('started_at')->values() as $index => $game) {
                    $meta = $gameMeta[$index] ?? [];

                    if (! empty($meta['turn_count'])) {
                        $game->update(['turn_count' => $meta['turn_count']]);
                    }

                    if (! $localName) {
                        continue;
                    }

                    if (empty($meta['dice_rolls']) && empty($meta['mulligans'])) {
                        continue;
                    }

                    $updates = [];

                    if (isset($meta['mulligans'][$localName])) {
                        $updates['local_mulligans'] = $meta['mulligans'][$localName];
                    }

                    if ($opponentName && isset($meta['mulligans'][$opponentName])) {
                        $updates['opp_mulligans'] = $meta['mulligans'][$opponentName];
                    }

                    if (! empty($meta['dice_rolls'][$localName])) {
                        $updates['local_dice'] = $meta['dice_rolls'][$localName];
                    }

                    if ($opponentName && ! empty($meta['dice_rolls'][$opponentName])) {
                        $updates['opp_dice'] = $meta['dice_rolls'][$opponentName];
                    }

                    if (! empty($updates)) {
                        $game->update($updates);
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
