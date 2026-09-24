<?php

namespace App\Actions\Replays;

use App\Exceptions\Replays\ReplayShareRefused;
use App\Models\Account;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Services\Sync\SyncApi;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mymtgo\Replay\Actions\PrepareSharedSnapshot;
use Mymtgo\Replay\Exceptions\RedactionFailed;

class ShareReplay
{
    /**
     * Uploads a game's whole match and returns the link, opening on that game.
     *
     * Every share uploads: the server keeps one link per match and refreshes
     * its snapshot, so sharing again after more games are played brings them
     * in. The opponent's name is removed before anything leaves the machine,
     * and the package validator runs here too, so a snapshot the site would
     * refuse is caught without a round trip.
     *
     * @throws ReplayShareRefused
     */
    public static function run(Game $game): string
    {
        $match = $game->match;
        $local = $game->localPlayers->first();
        $loginId = $local === null ? null : Account::where('username', $local->username)->value('login_id');

        if ($loginId === null) {
            throw new ReplayShareRefused(ReplayShareRefused::ACCOUNT_UNKNOWN);
        }

        $snapshot = BuildReplaySnapshot::run($match);

        if ($snapshot['games'] === []) {
            throw new ReplayShareRefused(ReplayShareRefused::INVALID);
        }

        try {
            $snapshot = PrepareSharedSnapshot::run($snapshot, self::opponentNames($match));
        } catch (RedactionFailed) {
            throw new ReplayShareRefused(ReplayShareRefused::NAME_SURVIVED);
        } catch (ValidationException $e) {
            Log::warning('Replay snapshot failed validation', ['match' => $match->id, 'errors' => array_slice($e->errors(), 0, 5)]);

            throw new ReplayShareRefused(ReplayShareRefused::INVALID);
        }

        $shared = app(SyncApi::class)->shareReplay($match->token, (int) $loginId, $snapshot);

        // Without timestamps: the link is local bookkeeping, and a newer
        // updated_at would mark the match dirty and re-upload its bundle.
        MtgoMatch::withoutTimestamps(fn () => $match->update([
            'replay_share_uuid' => $shared['uuid'],
            'replay_share_url' => $shared['url'],
            'replay_shared_at' => now(),
        ]));

        return self::linkToGame($shared['url'], $game);
    }

    /** The match link, opening on the given game. */
    public static function linkToGame(string $url, Game $game): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'game='.BuildReplaySnapshot::gameNumber($game);
    }

    /**
     * Every opponent name across the match's games, so a name only one game
     * recorded is still removed from all of them.
     *
     * @return list<string>
     */
    private static function opponentNames(MtgoMatch $match): array
    {
        return $match->games()
            ->with('opponents')
            ->get()
            ->flatMap(fn (Game $game) => $game->opponents->map(fn (Player $player) => $player->username))
            ->unique()
            ->values()
            ->all();
    }
}
