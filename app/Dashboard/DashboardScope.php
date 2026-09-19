<?php

namespace App\Dashboard;

use App\Actions\Util\TimeframeRange;
use App\Models\Account;
use Illuminate\Support\Carbon;

/**
 * Everything a widget needs to scope its queries: whose data, which window.
 */
final readonly class DashboardScope
{
    public function __construct(
        public ?int $accountId,
        public string $timeframe,
        public Carbon $start,
        public Carbon $end,
    ) {}

    public static function fromTimeframe(string $timeframe): self
    {
        [$start, $end] = TimeframeRange::run($timeframe);

        return new self(Account::currentId(), $timeframe, Carbon::instance($start), Carbon::instance($end));
    }
}
