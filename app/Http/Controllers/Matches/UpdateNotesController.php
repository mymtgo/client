<?php

namespace App\Http\Controllers\Matches;

use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class UpdateNotesController
{
    public function __invoke(string $id, Request $request)
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        MtgoMatch::where('id', $id)->update(['notes' => $request->input('notes')]);

        return redirect()->back();
    }
}
