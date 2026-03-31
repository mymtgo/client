<?php

namespace App\Updates;

use App\Jobs\BackfillCardDetails as BackfillCardDetailsJob;

class BackfillCardDetails extends AppUpdate
{
    public function version(): string
    {
        return '0.9.6';
    }

    public function run(): void
    {
        BackfillCardDetailsJob::dispatch();
    }
}
