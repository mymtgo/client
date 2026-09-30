<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ApplyCardImagesPath;
use App\Actions\Settings\QueueCardImagesMove;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ResetCardImagesPathController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        QueueCardImagesMove::run(ApplyCardImagesPath::defaultRoot());

        return back();
    }
}
