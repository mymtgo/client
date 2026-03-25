<?php

namespace App\Observers;

use App\Actions\Matches\ParseMatchHistory;
use App\Actions\Matches\ResolveMatchFromHistory;
use App\Enums\MatchState;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class LogCursorObserver
{
    public function updated(LogCursor $cursor): void
    {
        $previousOffset = $cursor->getOriginal('byte_offset');
        $currentOffset = $cursor->byte_offset;

        // Only trigger on cursor reset (offset went backwards = new MTGO session)
        if ($previousOffset === null || $currentOffset >= $previousOffset) {
            return;
        }

        Log::channel('pipeline')->info("LogCursor reset detected: {$previousOffset} → {$currentOffset}");

        $this->resolveIncompleteMatches();
    }

    private function resolveIncompleteMatches(): void
    {
        $incompleteMatches = MtgoMatch::whereIn('state', [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ])->whereNull('failed_at')->get();

        if ($incompleteMatches->isEmpty()) {
            return;
        }

        Log::channel('pipeline')->info("Cursor reset: resolving {$incompleteMatches->count()} incomplete matches via match history");

        foreach ($incompleteMatches as $match) {
            $result = ParseMatchHistory::findResult($match->mtgo_id);

            if ($result === null) {
                Log::channel('pipeline')->info("Match {$match->mtgo_id}: not found in match history, leaving as {$match->state->value}");

                continue;
            }

            ResolveMatchFromHistory::run($match, $result);
        }
    }
}
