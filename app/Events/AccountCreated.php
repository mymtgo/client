<?php

namespace App\Events;

use App\Models\Account;
use Illuminate\Foundation\Events\Dispatchable;

class AccountCreated
{
    use Dispatchable;

    public function __construct(public Account $account) {}
}
