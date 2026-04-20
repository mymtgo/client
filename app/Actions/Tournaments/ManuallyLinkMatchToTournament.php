<?php

namespace App\Actions\Tournaments;

use App\Models\MtgoMatch;
use App\Models\Tournament;

class ManuallyLinkMatchToTournament
{
    /**
     * Link (or relink) a match to a tournament.
     *
     * Writes tournament_id, tournament_round, and participant_login_ids
     * (derived from the match's own players' login_id values, nulls filtered).
     * Dispatches the login-id backfill so standings can resolve usernames.
     */
    public static function link(MtgoMatch $match, Tournament $tournament, int $round): void
    {
        $loginIds = $match->games()
            ->join('game_player', 'game_player.game_id', '=', 'games.id')
            ->join('players', 'players.id', '=', 'game_player.player_id')
            ->whereNotNull('players.login_id')
            ->pluck('players.login_id')
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();

        $match->update([
            'tournament_id' => $tournament->id,
            'tournament_round' => $round,
            'participant_login_ids' => $loginIds,
        ]);

        BackfillTournamentPlayerLoginIds::run($match->fresh());
    }

    public static function unlink(MtgoMatch $match): void
    {
        $match->update([
            'tournament_id' => null,
            'tournament_round' => null,
            'participant_login_ids' => null,
        ]);
    }
}
