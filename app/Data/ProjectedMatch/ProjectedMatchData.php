<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Data;

/**
 * The `{match}.json` envelope — the compiler's output and the cloud sink's
 * input. Serializes to exactly the contract in docs/v1/contract/spec.md.
 */
class ProjectedMatchData extends Data
{
    public function __construct(
        public int $schema_version,
        public string $client_version,
        public string $source,
        public string $match_key,
        public ?string $compiled_at,
        public int $file_version,
        public bool $imported,
        public ?string $mtgo_username,
        public ?int $mtgo_player_id,
        public MatchData $match,
    ) {}
}
