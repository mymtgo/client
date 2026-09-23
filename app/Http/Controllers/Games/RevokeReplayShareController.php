<?php

namespace App\Http\Controllers\Games;

use App\Actions\Replays\RevokeReplayShare;
use App\Exceptions\OfflineModeException;
use App\Exceptions\Sync\NotLinkedException;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RevokeReplayShareController extends Controller
{
    public function __invoke(Game $game): RedirectResponse
    {
        try {
            RevokeReplayShare::run($game->match);
        } catch (NotLinkedException) {
            return back()->withErrors(['share' => 'Sign in from Settings to switch this link off.']);
        } catch (OfflineModeException) {
            return back()->withErrors(['share' => 'Switching a link off needs a connection. Turn off offline mode first.']);
        } catch (ConnectionException|RuntimeException $e) {
            Log::warning('Replay share revoke failed', ['game' => $game->id, 'status' => $e->getCode(), 'error' => $e->getMessage()]);

            return back()->withErrors(['share' => 'Could not reach '.ShareReplayController::host().'. The link is still on; try again in a moment.']);
        }

        return back();
    }
}
