<?php

namespace App\Listeners\Pipeline;

use App\Actions\Matches\GetGameLog;
use App\Actions\Matches\SyncGameResults;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\MatchState;
use App\Events\MatchJoined;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CreateMatch
{
    public function handle(MatchJoined $event): void
    {
        $logEvent = $event->logEvent;

        // Look up by event identifiers — match_state_changed events don't carry match_id
        $existing = MtgoMatch::findByEvent($logEvent);

        if ($existing) {
            return;
        }

        // A new match starting means any prior incomplete matches are over
        // (e.g. quit during sideboarding produces no end signal from MTGO)
        self::resolvePriorMatches($logEvent);

        $gameMeta = ExtractKeyValueBlock::run($logEvent->raw_text);

        $started = Carbon::parse($logEvent->logged_at)
            ->setTimeFromTimeString($logEvent->timestamp);

        MtgoMatch::create([
            'token' => $logEvent->match_token,
            'format' => $gameMeta['PlayFormatCd'] ?? 'Unknown',
            'match_type' => $gameMeta['GameStructureCd'] ?? 'Unknown',
            'started_at' => $started,
            'ended_at' => $started,
            'state' => MatchState::Started,
            // mtgo_id will be backfilled by StoreMatchMetadata when
            // game_management_json event arrives (which carries MatchID)
        ]);

        Log::channel('pipeline')->info('Match created in Started state', [
            'token' => $logEvent->match_token,
        ]);
    }

    private static function resolvePriorMatches(LogEvent $logEvent): void
    {
        $staleMatches = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->where('token', '!=', $logEvent->match_token)
            ->get();

        foreach ($staleMatches as $match) {
            $lastEvent = LogEvent::where(function ($q) use ($match) {
                $q->where('match_token', $match->token)
                    ->orWhere('match_id', $match->mtgo_id);
            })->orderBy('id', 'desc')->first();

            $ended = $lastEvent
                ? Carbon::parse($lastEvent->logged_at)->setTimeFromTimeString($lastEvent->timestamp)
                : now();

            $match->update([
                'ended_at' => $ended,
                'state' => MatchState::Ended,
            ]);

            // Sync game results from the .dat file before completion
            $gameLog = GetGameLog::run($match->token);
            if ($gameLog) {
                SyncGameResults::run($match, $gameLog['results'] ?? []);
            }

            Log::channel('pipeline')->info('CreateMatch: resolved prior match', [
                'match_id' => $match->mtgo_id,
                'token' => $match->token,
            ]);
        }
    }
}
