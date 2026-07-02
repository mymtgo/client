<?php

namespace App\Actions\Compile;

use App\Actions\Compile\OutcomeResolvers\ConcessionResolver;
use App\Actions\Compile\OutcomeResolvers\ExplicitResultResolver;
use App\Actions\Compile\OutcomeResolvers\GameTallyResolver;
use App\Actions\Compile\OutcomeResolvers\ServerClosedTallyResolver;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ExtractMetaMessageEntries;
use App\Data\ProjectedMatch\MatchData;
use App\Enums\MatchOutcome;
use App\Enums\OutcomeSource;
use App\Models\LogEvent;

/**
 * Ordered outcome resolution — the first confident strategy wins; none
 * confident → Unknown (surfaced in the needs-attention UI, where a manual
 * outcome is baked in with outcome_source=manual).
 */
final class ResolveMatchOutcome
{
    /** Order matters: most authoritative first. */
    private const RESOLVERS = [
        ExplicitResultResolver::class,   // MTGO "wins the match X-Y" line
        GameTallyResolver::class,        // threshold-met game tally
        ConcessionResolver::class,       // local ConcedeReqState → NotJoined
        ServerClosedTallyResolver::class, // server closed below threshold → leaning tally
    ];

    /**
     * @return array{outcome: MatchOutcome, outcome_source: OutcomeSource}
     */
    public function run(MatchData $match, string $localUsername): array
    {
        $entries = ExtractMetaMessageEntries::run($match->token);
        $extracted = empty($entries) ? null : ExtractGameResults::run($entries, $localUsername);

        $stateChanges = LogEvent::query()
            ->where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        foreach (self::RESOLVERS as $class) {
            $outcome = app($class)->attempt($match, $extracted, $stateChanges);

            if ($outcome !== null) {
                return ['outcome' => $outcome, 'outcome_source' => OutcomeSource::Resolved];
            }
        }

        return ['outcome' => MatchOutcome::Unknown, 'outcome_source' => OutcomeSource::Unknown];
    }
}
