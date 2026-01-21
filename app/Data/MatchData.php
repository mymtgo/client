<?php

namespace App\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class MatchData extends Data
{
    public function __construct(
        public Carbon $date,
        public string $matchToken,
        public string $matchId,
        public string $format,
        public string $type,
        public Collection $games,
    ) {}

    public static function fromArray(array $data): self
    {
        $type = 'league';

        if (str_starts_with($data['PlayFormatCd'], 'C')) {
            $type = 'casual';
        }

        return new self(
            date: $data['date'],
            matchToken: $data['MatchToken'],
            matchId: $data['MatchID'],
            format: strtolower($data['GameStructureCd']),
            type: $type,
            games: collect(),
        );
    }
}
