<?php

namespace App\Concerns;

use App\Actions\Util\TimeframeRange;
use Carbon\Carbon;

trait HasTimeframeFilter
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getTimeRange(string $timeframe): array
    {
        return TimeframeRange::run($timeframe);
    }
}
