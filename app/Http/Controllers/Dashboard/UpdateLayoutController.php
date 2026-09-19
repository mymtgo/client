<?php

namespace App\Http\Controllers\Dashboard;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateLayoutRequest;
use Illuminate\Http\RedirectResponse;

class UpdateLayoutController extends Controller
{
    public function __invoke(UpdateLayoutRequest $request): RedirectResponse
    {
        AppSettings::setDashboardLayout($request->layout());

        return back();
    }
}
