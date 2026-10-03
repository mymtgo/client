<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Inertia\Inertia;
use Inertia\Response;

class GeneralController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('settings/General', [
            'currentPage' => 'general',
            'accounts' => Account::orderBy('username')->get(['id', 'username', 'tracked', 'active']),
            'autostartEnabled' => AppSettings::autostartEnabled(),
            'trayAvailable' => PHP_OS_FAMILY !== 'Linux',
        ]);
    }
}
