<?php

namespace App\Updates;

use App\Jobs\RepairGuessedMultiFaceCards as RepairGuessedMultiFaceCardsJob;

class RepairGuessedMultiFaceCards extends AppUpdate
{
    public function run(): void
    {
        RepairGuessedMultiFaceCardsJob::dispatch();
    }
}
