<?php

namespace App\Actions\Tournaments;

use App\Models\MtgoMatch;
use App\Models\Player;

class BackfillTournamentPlayerLoginIds
{
    /**
     * Populate players.login_id for the participants of a tournament match
     * by elimination: if one participant's login_id is already known, the
     * other participant gets the remaining ID from participant_login_ids.
     */
    public static function run(MtgoMatch $match): void
    {
        $pair = $match->participant_login_ids;

        if (! is_array($pair) || count($pair) !== 2) {
            return;
        }

        [$a, $b] = $pair;

        $localPlayerId = $match->games()
            ->join('game_player', 'game_player.game_id', '=', 'games.id')
            ->where('game_player.is_local', true)
            ->value('game_player.player_id');

        $opponentPlayerId = $match->games()
            ->join('game_player', 'game_player.game_id', '=', 'games.id')
            ->where('game_player.is_local', false)
            ->value('game_player.player_id');

        if (! $localPlayerId || ! $opponentPlayerId) {
            return;
        }

        $local = Player::find($localPlayerId);
        $opponent = Player::find($opponentPlayerId);

        if (! $local || ! $opponent) {
            return;
        }

        $localKnown = $local->login_id !== null;
        $opponentKnown = $opponent->login_id !== null;

        if ($localKnown && $opponentKnown) {
            return;
        }

        if ($opponentKnown) {
            $remaining = $opponent->login_id === $a ? $b : $a;
            $local->update(['login_id' => $remaining]);

            return;
        }

        if ($localKnown) {
            $remaining = $local->login_id === $a ? $b : $a;
            $opponent->update(['login_id' => $remaining]);

            return;
        }

        // Neither is known — defer.
    }
}
