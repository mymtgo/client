<?php

namespace App\Data\Front;

use App\Models\League;
use App\Support\MtgoFormat;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/** @typescript  */
class LeagueData extends Data
{
    public function __construct(
        public string $name,
        public Carbon $startedAt,
        public string $format,
        public bool $manual,
        public Collection $matches,
    ) {}

    public static function fromModel(League $league): self
    {
        return new self(
            name: $league->name,
            startedAt: $league->started_at,
            format: MtgoFormat::display($league->format),
            manual: (bool) $league->manual,
            matches: MatchData::collect($league->matches),
        );
    }
}
