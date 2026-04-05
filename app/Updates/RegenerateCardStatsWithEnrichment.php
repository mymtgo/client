<?php

namespace App\Updates;

use App\Actions\RegenerateCardGameStats;

class RegenerateCardStatsWithEnrichment extends AppUpdate
{
    public function run(): void
    {
        RegenerateCardGameStats::run();
    }
}
