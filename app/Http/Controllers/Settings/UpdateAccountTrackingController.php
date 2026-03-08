<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateAccountTrackingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => 'required|string|exists:accounts,username',
            'tracked' => 'required|boolean',
        ]);

        Account::where('username', $request->input('username'))
            ->update(['tracked' => $request->boolean('tracked')]);

        return back();
    }
}
