<?php

namespace App\Http\Controllers\Debug\LogCursors;

use App\Http\Controllers\Controller;
use App\Models\LogCursor;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    /**
     * Force-reset a cursor by deleting it. The next pipeline tick will
     * recreate it at byte_offset 0 and re-ingest from the start of the
     * file, which is the recovery path for users whose cursor is stuck
     * past the data they need (or otherwise wedged).
     */
    public function __invoke(int $id): RedirectResponse
    {
        $cursor = LogCursor::findOrFail($id);
        $cursor->delete();

        return back();
    }
}
