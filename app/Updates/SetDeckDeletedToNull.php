<?php

namespace App\Updates;

use App\Models\Deck;

class SetDeckDeletedToNull extends AppUpdate
{
    public function run(): void
    {
        Deck::withTrashed()->update(['deleted_at' => null]);
    }
}
