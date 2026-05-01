<?php

namespace App\Http\Controllers\Leagues;

use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateNotesController extends Controller
{
    public function __invoke(Request $request, League $league): RedirectResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $league->update(['notes' => $request->input('notes')]);

        return redirect()->back();
    }
}
