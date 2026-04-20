<?php

namespace App\Enums;

enum LogEventType: string
{
    case MATCH_STATE_CHANGED = 'match_state_changed';
    case GAME_STATE_UPDATE = 'game_state_update';
    case DECK_USED = 'deck_used';
    case LEAGUE_JOIN_REQUEST = 'league_join_request';
    case LEAGUE_JOINED = 'league_joined';
    case TOURNAMENT_SYNC = 'tournament_sync';
    case TOURNAMENT_STATE_CHANGED = 'tournament_state_changed';
    case TOURNAMENT_ROUND_RESULT = 'tournament_round_result';
    case TOURNAMENT_PLAYER_ELIMINATED = 'tournament_player_eliminated';
    case TOURNAMENT_ENDED = 'tournament_ended';
    case TOURNAMENT_MATCH_STATE_CHANGED = 'tournament_match_state_changed';
}
