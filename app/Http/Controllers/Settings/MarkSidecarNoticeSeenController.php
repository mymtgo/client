<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class MarkSidecarNoticeSeenController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        AppSettings::setSidecarNoticeSeen(true);

        return back();
    }
}
