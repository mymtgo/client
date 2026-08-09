<?php

namespace App\Actions\Matches;

use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

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

        self::inheritTournamentDecks();
    }

    /**
     * Adopt the deck version its sibling rounds are using for any tournament
     * match still missing one.
     *
     * The deck-relink pass above can only work while the match's `deck_used`
     * log events still exist, and PruneProcessedLogEvents deletes those the
     * moment the match reaches Complete. A round that was still unlinked at
     * that point — round 1 typically, because it starts before SyncDecks has
     * picked up a list the user finished minutes earlier — can never recover
     * from the log again, and drops out of every deck-scoped view (the
     * tournament runs query inner-joins deck_versions).
     *
     * MTGO locks the registered list for the whole event, so the other rounds
     * are authoritative. Inherit only when every linked sibling agrees, so a
     * tournament row shared by two runs on different decks is left alone.
     *
     * Deliberately not windowed by date: this repairs historic rounds too, a
     * limited batch per tick until there is nothing left to fix.
     */
    private static function inheritTournamentDecks(int $limit = 50): void
    {
        MtgoMatch::query()
            ->whereNull('deck_version_id')
            ->whereNotNull('tournament_id')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->each(function (MtgoMatch $match) {
                $siblingVersionIds = MtgoMatch::query()
                    ->where('tournament_id', $match->tournament_id)
                    ->whereNotNull('deck_version_id')
                    ->distinct()
                    ->pluck('deck_version_id');

                if ($siblingVersionIds->count() !== 1) {
                    return;
                }

                $match->update(['deck_version_id' => $siblingVersionIds->first()]);

                Log::channel('pipeline')->info("Match {$match->mtgo_id}: inherited deck version from tournament siblings", [
                    'match_id' => $match->id,
                    'tournament_id' => $match->tournament_id,
                    'tournament_round' => $match->tournament_round,
                    'deck_version_id' => $siblingVersionIds->first(),
                ]);
            });
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
