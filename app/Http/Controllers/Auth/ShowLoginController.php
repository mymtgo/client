<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ShowLoginController extends Controller
{
    /**
     * The signed-out page shown inside the dedicated auth window.
     */
    public function __invoke(): Response
    {
        return Inertia::render('auth/Login');
    }
}
