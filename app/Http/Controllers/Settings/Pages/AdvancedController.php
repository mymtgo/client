<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AdvancedController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('settings/Advanced', [
            'currentPage' => 'advanced',
            'debugMode' => AppSettings::isDebugMode(),
            'appVersion' => config('nativephp.version'),
        ]);
    }
}
