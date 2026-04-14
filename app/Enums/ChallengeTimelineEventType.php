<?php

namespace App\Enums;

enum ChallengeTimelineEventType: string
{
    case StateChanged = 'state_changed';
    case RoundResult = 'round_result';
    case PlayerEliminated = 'player_eliminated';
    case MatchStateChanged = 'match_state_changed';
}
