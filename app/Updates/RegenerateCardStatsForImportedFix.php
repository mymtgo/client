<?php

namespace App\Updates;

use App\Actions\RegenerateCardGameStats;

class RegenerateCardStatsForImportedFix extends AppUpdate
{
    public function run(): void
    {
        RegenerateCardGameStats::run();
    }
}
