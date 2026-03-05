<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SubmitMatch;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Native\Desktop\Facades\Settings;

class RunSubmitMatchesController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        if (Settings::get('share_stats')) {
            MtgoMatch::submittable()
                ->get()
                ->each(fn (MtgoMatch $match) => SubmitMatch::dispatchSync($match->id));
        }

        return back();
    }
}
