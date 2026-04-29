<?php

namespace App\Updates;

use App\Jobs\RegenerateDeckSignaturesJob;

class RegenerateDeckSignatures extends AppUpdate
{
    public function run(): void
    {
        RegenerateDeckSignaturesJob::dispatchSync();
    }
}
