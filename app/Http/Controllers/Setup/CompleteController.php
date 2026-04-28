<?php

namespace App\Http\Controllers\Setup;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompleteController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        AppSettings::setSetupCompleted(true);

        return $request->input('next') === 'import'
            ? redirect('/import')
            : redirect('/');
    }
}
