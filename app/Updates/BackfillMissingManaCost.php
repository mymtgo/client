<?php

namespace App\Updates;

use App\Jobs\BackfillCardDetails as BackfillCardDetailsJob;

class BackfillMissingManaCost extends AppUpdate
{
    public function run(): void
    {
        BackfillCardDetailsJob::dispatch();
    }
}
