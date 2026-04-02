<?php

namespace App\Updates;

use App\Jobs\ReprocessImportedCardDataJob;

class ReprocessImportedCardOwnership extends AppUpdate
{
    public function run(): void
    {
        ReprocessImportedCardDataJob::dispatch();
    }
}
