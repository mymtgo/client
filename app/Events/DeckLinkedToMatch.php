<?php

namespace App\Events;

use App\Models\MtgoMatch;
use Illuminate\Foundation\Events\Dispatchable;

class DeckLinkedToMatch
{
    use Dispatchable;

    public function __construct(public MtgoMatch $match) {}
}
