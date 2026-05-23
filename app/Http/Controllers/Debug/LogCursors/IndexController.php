<?php

namespace App\Http\Controllers\Debug\LogCursors;

use App\Http\Controllers\Controller;
use App\Models\LogCursor;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('debug/LogCursors', [
            'logCursors' => LogCursor::query()
                ->with('logInstance')
                ->orderByDesc('id')
                ->paginate(50),
        ]);
    }
}
