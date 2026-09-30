<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\QueueCardImagesMove;
use App\Http\Controllers\Controller;
use App\Jobs\MoveCardImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateCardImagesPathController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $chosen = rtrim($request->string('path')->toString(), '/\\');

        QueueCardImagesMove::run($chosen.DIRECTORY_SEPARATOR.MoveCardImages::SUBFOLDER);

        return back();
    }
}
