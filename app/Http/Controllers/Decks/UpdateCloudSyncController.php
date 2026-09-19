<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Sync\SetDeckCloudSync;
use App\Exceptions\Sync\LimitedRequiresSupporterException;
use App\Exceptions\Sync\NotLinkedException;
use App\Exceptions\Sync\SlotLimitException;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UpdateCloudSyncController extends Controller
{
    public function __invoke(Deck $deck, Request $request): RedirectResponse
    {
        abort_if($deck->trashed(), 403, 'This deck has been deleted on MTGO and is read-only.');

        $request->validate(['enabled' => 'required|boolean']);

        try {
            SetDeckCloudSync::run($deck, $request->boolean('enabled'));
        } catch (NotLinkedException) {
            abort(409, 'Sign in from Settings before syncing decks.');
        } catch (SlotLimitException $e) {
            return back()->withErrors(['cloud_sync' => $this->slotLimitMessage($e)]);
        } catch (LimitedRequiresSupporterException) {
            return back()->withErrors([
                'cloud_sync' => 'Syncing draft and sealed decks is a supporter feature.',
            ]);
        }

        return back();
    }

    private function slotLimitMessage(SlotLimitException $e): string
    {
        if ($e->freesAt === null) {
            $held = Deck::query()->where('cloud_sync_enabled', true)->value('name');

            return $held === null
                ? 'Your free deck slot is in use. Turn off sync on another deck first.'
                : "Your free deck slot is in use by {$held}. Turn off sync on that deck first.";
        }

        return 'Your free deck slot is cooling down. It frees on '.Carbon::parse($e->freesAt)->toLocal()->format('j M Y').'.';
    }
}
