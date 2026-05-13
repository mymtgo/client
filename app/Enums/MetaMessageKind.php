<?php

namespace App\Enums;

enum MetaMessageKind: string
{
    case DeckList = 'deck_list';
    case OpponentName = 'opponent_name';
    case DieRoll = 'die_roll';
    case PlayChoice = 'play_choice';
    case Mulligan = 'mulligan';
    case StartingHand = 'starting_hand';
    case TurnStart = 'turn_start';
    case CastCard = 'cast_card';
    case PlayCard = 'play_card';
    case GameWinner = 'game_winner';
    case Concede = 'concede';
    case Joined = 'joined';
    case Chat = 'chat';
    case UiPrompt = 'ui_prompt';
    case System = 'system';
    case Unknown = 'unknown';
}
