<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchAccountController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => 'required|string|exists:accounts,username',
        ]);

        $account = Account::where('username', $request->input('username'))->firstOrFail();
        $account->activate();

        return redirect()->route('decks.index');
    }
}
