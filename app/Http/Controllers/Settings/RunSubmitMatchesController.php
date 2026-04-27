<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\SubmitMatch;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class RunSubmitMatchesController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        if (AppSettings::shouldTransmitMatches()) {
            MtgoMatch::submittable()
                ->get()
                ->each(fn (MtgoMatch $match) => SubmitMatch::dispatch($match->id));
        }

        return back();
    }
}
