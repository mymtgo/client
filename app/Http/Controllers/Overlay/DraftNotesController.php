<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Limited\Read\BuildDraftNotes;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DraftNotesController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('overlay/DraftNotes', [
            'notes' => fn () => BuildDraftNotes::run(),
            // Lets the page correct its countdown for clock skew once; the
            // window polls `notes` only, so this is not recomputed per tick.
            'serverNow' => fn () => now()->toIso8601String(),
        ]);
    }
}
