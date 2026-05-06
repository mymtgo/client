<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateNameController extends Controller
{
    public function __invoke(Deck $deck, Request $request): RedirectResponse
    {
        abort_if($deck->trashed(), 403, 'This deck has been deleted on MTGO and is read-only.');

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $newName = trim($data['name']);

        if ($newName === '' || $newName === $deck->name) {
            return back();
        }

        // First rename: capture the MTGO-supplied name so we can revert later
        // and so SyncDecks knows to stop overwriting `name` on resync.
        if (! $deck->original_name) {
            $deck->original_name = $deck->name;
        }

        $deck->name = $newName;

        // Reverting back to the original name clears the custom-rename flag,
        // letting MTGO drive the name again on the next sync.
        if ($deck->original_name === $newName) {
            $deck->original_name = null;
        }

        $deck->save();

        return back();
    }
}
