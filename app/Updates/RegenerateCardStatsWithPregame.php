<?php

namespace App\Updates;

use App\Actions\RegenerateCardGameStats;

class RegenerateCardStatsWithPregame extends AppUpdate
{
    public function run(): void
    {
        RegenerateCardGameStats::run();
    }
}
