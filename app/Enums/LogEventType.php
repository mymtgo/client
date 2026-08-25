<?php

namespace App\Enums;

enum LogEventType: string
{
    case MATCH_STATE_CHANGED = 'match_state_changed';
    case GAME_STATE_UPDATE = 'game_state_update';
    case DECK_USED = 'deck_used';
    case LEAGUE_JOIN_REQUEST = 'league_join_request';
    case LEAGUE_JOINED = 'league_joined';
    case LEAGUE_DROPPED = 'league_dropped';

    case TOURNAMENT_SYNC = 'tournament_sync';
    case TOURNAMENT_STATE_CHANGED = 'tournament_state_changed';
    case TOURNAMENT_ROUND_RESULT = 'tournament_round_result';
    case TOURNAMENT_ROUND_INFO = 'tournament_round_info';
    case TOURNAMENT_PLAYER_ELIMINATED = 'tournament_player_eliminated';
    case TOURNAMENT_ENDED = 'tournament_ended';
    case TOURNAMENT_MATCH_STATE_CHANGED = 'tournament_match_state_changed';

    case DRAFT_CREATED = 'draft_created';
    case DRAFT_LEAGUE_STANDING = 'draft_league_standing';
    case DRAFT_JOINED = 'draft_joined';
    case DRAFT_POD_STATE = 'draft_pod_state';
    case DRAFT_PACK_OPENED = 'draft_pack_opened';
    case DRAFT_PENDING_PICK = 'draft_pending_pick';
    case DRAFT_SELECTION = 'draft_selection';
    case DRAFT_PICK_COMMITTED = 'draft_pick_committed';
    case DRAFT_ENDED = 'draft_ended';
    case DRAFT_STATE_CHANGED = 'draft_state_changed';
    case LEAGUE_POOL_GRANTED = 'league_pool_granted';
    case MATCH_DECK_REGISTERED = 'match_deck_registered';

    /**
     * @return array<string> Tournament event_type values that can be enqueued
     *                       for shipping to the API. TOURNAMENT_MATCH_STATE_CHANGED
     *                       is excluded — no real MTGO log produces it; revisit
     *                       when per-match tournament transitions are actually needed.
     */
    public static function tournamentValues(): array
    {
        return [
            self::TOURNAMENT_SYNC->value,
            self::TOURNAMENT_STATE_CHANGED->value,
            self::TOURNAMENT_ROUND_RESULT->value,
            self::TOURNAMENT_ROUND_INFO->value,
            self::TOURNAMENT_PLAYER_ELIMINATED->value,
            self::TOURNAMENT_ENDED->value,
        ];
    }

    /**
     * @return array<string> Every event_type ProcessDraftEvents consumes,
     *                       token-bearing and token-less alike.
     */
    public static function draftValues(): array
    {
        return [
            self::DRAFT_CREATED->value,
            self::DRAFT_LEAGUE_STANDING->value,
            self::DRAFT_JOINED->value,
            self::DRAFT_POD_STATE->value,
            self::DRAFT_PACK_OPENED->value,
            self::DRAFT_PENDING_PICK->value,
            self::DRAFT_SELECTION->value,
            self::DRAFT_PICK_COMMITTED->value,
            self::DRAFT_ENDED->value,
            self::DRAFT_STATE_CHANGED->value,
            self::LEAGUE_POOL_GRANTED->value,
        ];
    }
}
