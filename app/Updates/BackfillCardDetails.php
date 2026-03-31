<?php

namespace App\Updates;

use App\Jobs\BackfillCardDetails as BackfillCardDetailsJob;

class BackfillCardDetails extends AppUpdate
{
    public function run(): void
    {
        BackfillCardDetailsJob::dispatch();
    }
}
