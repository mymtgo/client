<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

class ProcessController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Artisan::call('mtgo:process-matches');

        return back();
    }
}
