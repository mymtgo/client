<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BuildAuthorizeUrl;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Native\Desktop\Facades\Shell;

class OpenWebsiteLoginController extends Controller
{
    /**
     * Send the user to the website's sign-in page in their system browser.
     * Each click stashes a fresh PKCE verifier + state, so the most recent
     * browser tab is the only one whose callback will be accepted.
     */
    public function __invoke(BuildAuthorizeUrl $buildUrl): RedirectResponse
    {
        Shell::openExternal($buildUrl->run());

        return back();
    }
}
