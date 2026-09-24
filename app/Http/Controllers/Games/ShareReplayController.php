<?php

namespace App\Http\Controllers\Games;

use App\Actions\Replays\ShareReplay;
use App\Exceptions\OfflineModeException;
use App\Exceptions\Replays\ReplayShareRefused;
use App\Exceptions\Sync\NotLinkedException;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShareReplayController extends Controller
{
    public function __invoke(Game $game): RedirectResponse
    {
        try {
            ShareReplay::run($game);
        } catch (NotLinkedException) {
            return back()->withErrors(['share' => 'Sign in from Settings to share replays.']);
        } catch (OfflineModeException) {
            return back()->withErrors(['share' => 'Sharing needs a connection. Turn off offline mode first.']);
        } catch (ReplayShareRefused $e) {
            return back()->withErrors(['share' => $this->message($e->reason)]);
        } catch (ConnectionException $e) {
            Log::warning('Replay share could not connect', ['game' => $game->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['share' => 'Could not reach '.self::host().'. Try again in a moment.']);
        } catch (RuntimeException $e) {
            Log::warning('Replay share failed', ['game' => $game->id, 'status' => $e->getCode(), 'error' => $e->getMessage()]);

            return back()->withErrors(['share' => self::host().' could not share this replay right now. Try again in a moment.']);
        }

        return back();
    }

    /** The API host this build talks to, so a local build names the local site in its errors. */
    public static function host(): string
    {
        return (string) (parse_url((string) config('mymtgo_api.url'), PHP_URL_HOST) ?: 'mymtgo.com');
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            ReplayShareRefused::SUPPORTER => 'Sharing replays is a supporter feature.',
            ReplayShareRefused::NOT_CLAIMED => 'Link your MTGO account to your '.self::host().' account first, so the replay can be filed under your player name. We could not confirm your claim to it.',
            ReplayShareRefused::TOO_LARGE => 'This game is too large to share.',
            ReplayShareRefused::ACCOUNT_UNKNOWN => 'Your MTGO account is not linked yet. Play a game with the app running, then try again.',
            ReplayShareRefused::NAME_SURVIVED => "Couldn't hide your opponent's name, so this replay was not shared.",
            default => 'This replay could not be prepared for sharing.',
        };
    }
}
