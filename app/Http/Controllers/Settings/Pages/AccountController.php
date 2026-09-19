<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('settings/Account', [
            'currentPage' => 'account',
        ]);
    }
}
