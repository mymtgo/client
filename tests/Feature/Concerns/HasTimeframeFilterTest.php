<?php

use App\Concerns\HasTimeframeFilter;
use App\Facades\AppSettings;
use Carbon\Carbon;

it('starts and ends the timeframe on local midnight in the system timezone', function () {
    AppSettings::setSystemTimezone('America/Los_Angeles');
    // 03:00 UTC on the 10th is 20:00 PDT on the 9th.
    Carbon::setTestNow(Carbon::parse('2026-09-10 03:00:00', 'UTC'));

    $filter = new class
    {
        use HasTimeframeFilter;

        public function range(string $timeframe): array
        {
            return $this->getTimeRange($timeframe);
        }
    };

    [$start, $end] = $filter->range('week');

    // Local week window: 2 Sep 00:00 PDT to 9 Sep 23:59:59 PDT, expressed in UTC.
    expect($start->utc()->toDateTimeString())->toBe('2026-09-02 07:00:00');
    expect($end->utc()->toDateTimeString())->toBe('2026-09-10 06:59:59');
});
