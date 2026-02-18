<?php

namespace App\Data\Front;

use App\Models\League;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/** @typescript  */
class LeagueData extends Data
{
    public function __construct(
        public string $name,
        public Carbon $startedAt,
        public bool $phantom,
        public string $format,
        public Collection $matches,
    ) {}

    public static function fromModel(League $league): self
    {
        return new self(
            name: $league->name,
            startedAt: $league->started_at,
            phantom: $league->phantom,
            format: Str::title(strtolower(substr($league->format, 1))),
            matches: MatchData::collect($league->matches),
        );
    }
}
