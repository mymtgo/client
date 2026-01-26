<?php

namespace App\Actions\Matches;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Native\Laravel\Facades\Notification;

class BuildMatch
{
    public static function run(string $matchToken, int|string $matchId): ?MtgoMatch
    {
        $events = LogEvent::where('match_id', $matchId)->orderBy('timestamp')->get();

        $stateChanges = LogEvent::where('match_token', $matchToken)->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)->get()->values();

        $idxConcede = $events->search(fn ($e) => str_contains($e->message ?? '', 'MatchConcedeRequest'));
        $idxNotJoined = $events->search(fn ($e) => str_contains($e->message ?? '', 'MatchNotJoinedUnderway'));

        $concededAndQuit = $idxConcede !== false
            && $idxNotJoined !== false
            && $idxNotJoined > $idxConcede;

        $idxCompletedAfterNotJoined = $events
            ->slice($idxNotJoined)
            ->search(fn ($e) => str_contains($e->message ?? '', 'MatchCompleted')
            );

        $matchEnded = $stateChanges->first(
            fn (LogEvent $event) => str_contains($event->context, 'TournamentMatchClosedState') || str_contains($event->context, 'MatchCompletedState') || str_contains($event->context, 'MatchEndedState') || str_contains($event->context, 'MatchClosedState')
        );

        $concededAndQuit = $concededAndQuit && $idxCompletedAfterNotJoined !== false;

        $canFinalize = $matchEnded || $concededAndQuit;

        $joinedState = $events->first(
            fn (LogEvent $event) => str_contains($event->context, 'MatchJoinedEventUnderwayState')
        );

        if (MtgoMatch::where('mtgo_id', $matchId)->exists()) {
            LogEvent::where('match_id', $matchId)
                ->orWhere('match_token', $matchId)
                ->update([
                    'processed_at' => now(),
                ]);

            return null;
        }

        if (! $joinedState || ! $canFinalize) {
            return null;
        }

        $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

        $league = null;

        if (! empty($gameMeta['League Token'])) {
            $league = League::firstOrCreate([
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
            ], [
                'started_at' => now(),
                'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
            ]);
        }

        $lastEvent = $events->last();

        $started = now()->parse($joinedState->logged_at)->setTimeFromTimeString($joinedState->timestamp);

        $ended = now()->parse($lastEvent->logged_at)->setTimeFromTimeString($lastEvent->timestamp);

        //        DB::beginTransaction();

        /**
         * Have we already got this match?
         */
        $match = MtgoMatch::create([
            'mtgo_id' => $matchId,
            'league_id' => $league?->id,
            'token' => $matchToken,
            'format' => $gameMeta['PlayFormatCd'],
            'match_type' => $gameMeta['GameStructureCd'],
            'started_at' => $started,
            'ended_at' => $ended,
        ]);

        $games = $events->groupBy('game_id');

        $gameIds = $games->keys();

        /**
         * Build out our initial deck, we use the first game as it's
         * a high chance it'll be the deck from the collection.
         */
        $decksEvents = LogEvent::where('event_type', 'deck_used')->whereIn('game_id', $gameIds)->get();

        $gameIndex = 0;

        foreach ($games as $gameId => $gameEvents) {
            $playerDeck = $decksEvents->first(
                fn ($event) => (int) $event->game_id === $gameId
            );

            $deckJson = ExtractJson::run($playerDeck->raw_text)->first() ?: [];

            CreateGames::run($match, $gameId, $gameEvents, $gameIndex, $deckJson);
            $gameIndex++;
        }

        DetermineMatchDeck::run($match);

        $gameLog = GetGameLog::run($match->token);
        $wins = $match->games->sum('won');
        $losses = count($gameLog['results']) - $wins;

        if ($wins == $losses || ! $losses) {
            /**
             * Did we concede from this game?
             */
            $conceded = $stateChanges->first(
                fn (LogEvent $event) => str_contains($event->context, 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState')
            );

            if ($conceded) {
                $losses++;
            }
        }

        $match->update([
            'games_won' => $wins,
            'games_lost' => $losses,
        ]);

        DetermineMatchArchetypes::run($match);

        //        DB::commit();

        Notification::title('New match Recorded')
            ->message($match->deck?->name.' // '.$match->games_won.'-'.$match->games_lost)
            ->show();

        LogEvent::where('match_id', $match->mtgo_id)
            ->orWhere('match_token', $match->token)
            ->orWhereIn('game_id', $match->games->pluck('mtgo_id'))
            ->update([
                'processed_at' => now(),
            ]);

        return $match;
    }
}
