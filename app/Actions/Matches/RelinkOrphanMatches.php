<?php

namespace App\Actions\Matches;

use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class RelinkOrphanMatches
{
    /**
     * Re-attempt deck linking and league assignment for matches that lack
     * one or the other.
     *
     * Deck relinking covers matches whose DeckVersion arrived after the
     * Started → InProgress boundary. League re-assignment covers the case
     * where AdvanceMatchState fell back to the match_state_changed event
     * (no Receiver: key=value block) because the game_management_json event
     * had not yet been ingested when the match advanced — AssignLeague
     * silently returned and there is no other code path that ever sets
     * league_id for an orphan.
     *
     * Both passes are scoped to InProgress / Ended / Complete states and
     * windowed by started_at so pre-Complete matches (no ended_at) are
     * still considered.
     */
    public static function run(int $limit = 20, int $withinDays = 7): void
    {
        $orphanStates = [
            MatchState::InProgress,
            MatchState::Ended,
            MatchState::Complete,
        ];

        MtgoMatch::whereIn('state', $orphanStates)
            ->whereNull('deck_version_id')
            ->where('started_at', '>', now()->subDays($withinDays))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->each(fn (MtgoMatch $match) => DetermineMatchDeck::run($match));

        MtgoMatch::whereIn('state', $orphanStates)
            ->whereNull('league_id')
            ->whereNull('tournament_event_id')
            ->where('started_at', '>', now()->subDays($withinDays))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->each(fn (MtgoMatch $match) => self::retryAssignLeague($match));
    }

    /**
     * Re-extract gameMeta from the most informative joined-state log event
     * for the match and hand it back to AssignLeague. Prefers the
     * game_management_json variant (carries Receiver: + key=value block);
     * falls back to the match_state_changed header only if no JSON variant
     * exists. AssignLeague itself is idempotent and short-circuits on an
     * empty League Token.
     */
    private static function retryAssignLeague(MtgoMatch $match): void
    {
        $joinedState = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'game_management_json')
            ->where('context', 'like', '%MatchJoinedEventUnderwayState%')
            ->orderByDesc('id')
            ->first()
            ?? LogEvent::where('match_token', $match->token)
                ->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)
                ->where('context', 'like', '%MatchJoinedEventUnderwayState%')
                ->orderByDesc('id')
                ->first();

        if (! $joinedState) {
            return;
        }

        $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

        if (empty($gameMeta['League Token'])) {
            return;
        }

        AssignLeague::run($match, $gameMeta);
    }
}
