<?php

namespace App\Actions\Compile;

use App\Actions\Auth\ResolveLocalIdentity;
use App\Data\ProjectedMatch\ProjectedMatchData;

/**
 * The compiler's front door: gate → project → resolve outcome → stamp the
 * envelope. Null means "nothing to push" — not our match, identity
 * unresolved/mismatched (hold, log nothing), or zero games (never valid).
 *
 * `file_version` is stamped 1 here; the outbox owns the monotonic bump on
 * re-enqueue (last-write-wins on the sink).
 */
final class CompileMatch
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private IsOurMatch $isOurMatch,
        private ProjectMatch $project,
        private ResolveMatchOutcome $resolveOutcome,
        private ResolveLocalIdentity $identity,
    ) {}

    public function run(string $matchKey): ?ProjectedMatchData
    {
        if (! $this->isOurMatch->run($matchKey)) {
            return null;
        }

        $identity = $this->identity->run();

        if ($identity === null) {
            return null;
        }

        $match = $this->project->run($matchKey, $identity->mtgoUsername);

        if ($match === null || $match->games === []) {
            return null;
        }

        $resolution = $this->resolveOutcome->run($match, $identity->mtgoUsername);
        $match->outcome = $resolution['outcome'];
        $match->outcome_source = $resolution['outcome_source'];

        return new ProjectedMatchData(
            schema_version: self::SCHEMA_VERSION,
            client_version: config('nativephp.version'),
            source: 'mtgo',
            match_key: $matchKey,
            compiled_at: now()->toIso8601String(),
            file_version: 1,
            imported: false,
            mtgo_username: $identity->mtgoUsername,
            mtgo_player_id: $identity->mtgoPlayerId,
            match: $match,
        );
    }
}
