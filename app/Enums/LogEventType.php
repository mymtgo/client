<?php

namespace App\Enums;

enum LogEventType: string
{
    case MATCH_STATE_CHANGED = 'match_state_changed';
    case GAME_STATE_UPDATE = 'game_state_update';
    case DECK_USED = 'deck_used';
    case LEAGUE_JOIN_REQUEST = 'league_join_request';
    case LEAGUE_JOINED = 'league_joined';
    case CHALLENGE_SYNC = 'challenge_sync';
    case CHALLENGE_STATE_CHANGED = 'challenge_state_changed';
    case CHALLENGE_ROUND_RESULT = 'challenge_round_result';
    case CHALLENGE_PLAYER_ELIMINATED = 'challenge_player_eliminated';
    case CHALLENGE_ENDED = 'challenge_ended';
    case CHALLENGE_MATCH_STATE_CHANGED = 'challenge_match_state_changed';
}
