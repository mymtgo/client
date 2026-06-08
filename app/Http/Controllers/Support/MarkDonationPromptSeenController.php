<?php

namespace App\Http\Controllers\Support;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class MarkDonationPromptSeenController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        AppSettings::setDonationPromptSeen(true);

        return back();
    }
}
