<?php

namespace App\Console\Commands;

use App\Actions\Cards\CreateMissingCardsFromLocalData;
use Illuminate\Console\Command;

class BackfillMissingCards extends Command
{
    protected $signature = 'app:backfill-missing-cards';

    protected $description = 'Create card stubs for every catalog id the local database already references';

    public function handle(): void
    {
        $created = CreateMissingCardsFromLocalData::run();

        $this->info(sprintf('%d card %s created.', $created, $created === 1 ? 'stub' : 'stubs'));
    }
}
