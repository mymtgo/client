<?php

namespace App\Actions\Accounts;

use App\Facades\Mtgo;
use App\Models\Account;

class FindLocalLoginId
{
    /**
     * The MTGO login id of the account signed in right now, or null when the
     * username is unknown (it is flaky) or that account has no login id yet.
     * Never guesses: a wrong id would publish to someone else's name.
     */
    public static function run(): ?int
    {
        $username = Mtgo::getUsername();

        if ($username === null) {
            return null;
        }

        $loginId = Account::query()->where('username', $username)->value('login_id');

        return $loginId === null ? null : (int) $loginId;
    }
}
