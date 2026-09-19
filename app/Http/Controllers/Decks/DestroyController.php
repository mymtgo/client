<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Sync\SetDeckCloudSync;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteDeckRequest;
use App\Models\Deck;
use App\Services\Sync\SyncTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class DestroyController extends Controller
{
    /**
     * Soft delete the deck. Matches, versions and stats are left intact so a
     * restore brings the deck's whole history back with it.
     */
    public function __invoke(Deck $deck, DeleteDeckRequest $request): RedirectResponse
    {
        if (! $deck->trashed()) {
            // A deleted deck stops syncing (spec 2026-09-10, rule 6). Best
            // effort: a network failure must not block the delete, and the
            // next manifest reconciles the flag either way.
            if ($deck->cloud_sync_enabled && app(SyncTokens::class)->linked()) {
                try {
                    SetDeckCloudSync::run($deck, false);
                } catch (\Throwable $e) {
                    Log::info('Could not turn off cloud sync for a deleted deck.', ['deck_id' => $deck->id, 'error' => $e->getMessage()]);
                    $deck->forceFill(['cloud_sync_enabled' => false])->saveQuietly();
                }
            }

            $deck->delete();
        }

        return to_route('decks.index');
    }
}
