<?php

namespace App\Http\Controllers\Games;

use App\Actions\Games\OpenGameReplayWindow;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class OpenReplayController extends Controller
{
    public function __invoke(string $id): RedirectResponse
    {
        OpenGameReplayWindow::run((int) $id);

        return back();
    }
}
