<?php

namespace App\Enums;

enum LogEventType: string
{
    case MATCH_STATE_CHANGED = 'match_state_changed';
    case GAME_STATE_UPDATE = 'game_state_update';
    case DECK_USED = 'deck_used';
    case LEAGUE_JOIN_REQUEST = 'league_join_request';
    case LEAGUE_JOINED = 'league_joined';
    case GAME_RESULT = 'game_result';
    case CARD_REVEALED = 'card_revealed';
    case GAME_STARTED = 'game_started';
    case MATCH_METADATA = 'match_metadata';
    case USER_LOGGED_IN = 'user_logged_in';
}
