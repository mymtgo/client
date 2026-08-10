<?php

namespace App\Http\Controllers\Overlay;

use App\Http\Controllers\Controller;
use App\Models\DeckArchetypeNote;
use Illuminate\Http\RedirectResponse;

class DestroyNoteController extends Controller
{
    public function __invoke(DeckArchetypeNote $note): RedirectResponse
    {
        $note->delete();

        return back();
    }
}
