<?php

namespace App\Enums;

enum TournamentState: string
{
    case AwaitingPlayers = 'awaiting_players';
    case Firing = 'firing';
    case Drafting = 'drafting';
    case DeckBuilding = 'deck_building';
    case WaitingForFirstRound = 'waiting_for_first_round';
    case RoundInProgress = 'round_in_progress';
    case BetweenRounds = 'between_rounds';
    case Completed = 'completed';

    /**
     * Map an MTGO state string to a TournamentState.
     */
    public static function fromMtgoState(string $mtgoState): ?self
    {
        return match (true) {
            str_contains($mtgoState, 'AwaitingMinPlayers'),
            str_contains($mtgoState, 'AwaitingMaxPlayers') => self::AwaitingPlayers,

            str_contains($mtgoState, 'AwaitingStart'),
            str_contains($mtgoState, 'FiredState') => self::Firing,

            str_contains($mtgoState, 'DraftingState') => self::Drafting,

            str_contains($mtgoState, 'DeckBuildingState') => self::DeckBuilding,

            str_contains($mtgoState, 'WaitingForFirstRoundToStart') => self::WaitingForFirstRound,

            str_contains($mtgoState, 'RoundInProgressState') => self::RoundInProgress,

            str_contains($mtgoState, 'BetweenRoundsState') => self::BetweenRounds,

            str_contains($mtgoState, 'CompletedState') => self::Completed,

            default => null,
        };
    }

    public function isActive(): bool
    {
        return $this !== self::Completed;
    }
}
