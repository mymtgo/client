<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\Leagues\LeagueFinishOdds;
use App\Support\MatchRecord;

class GetLeagueInProgress
{
    /**
     * How few league matches a deck can have before its league record stops
     * being worth predicting from and the deck's whole record is used instead.
     */
    private const MINIMUM_LEAGUE_MATCHES = 10;

    /**
     * The league run this deck is part way through, with where it is likely
     * to finish.
     *
     * The projection uses the deck's own league record rather than its
     * overall one, because league rounds are the thing being predicted and
     * casual matches are a different population. A deck that has barely
     * played leagues has no such record to use, so it falls back to its
     * overall win rate, and a deck with no record at all to a coin flip.
     * Which of the three was used is reported alongside the number.
     *
     * @return array{wins: int, losses: int, winProbability: int, oddsSource: string, odds: array{roundsLeft: int, prizeWins: int|null, winsForPrize: int|null, chanceOfPrize: int|null, expectedTix: float|null}}|null
     */
    public static function run(Deck $deck, ?DeckVersion $deckVersion = null): ?array
    {
        $league = League::query()
            ->where('state', LeagueState::Active)
            ->whereHas('matches', function ($q) use ($deck, $deckVersion) {
                $q->whereHas('deckVersion', fn ($dv) => $dv->where('deck_id', $deck->id))
                    ->when($deckVersion, fn ($m) => $m->where('matches.deck_version_id', $deckVersion->id));
            })
            ->with('deckVersion.deck.cover')
            ->orderByDesc('started_at')
            ->first();

        if (! $league) {
            return null;
        }

        $run = FormatLeagueRuns::run(collect([$league]))[0] ?? null;

        if (! $run) {
            return null;
        }

        $wins = count(array_filter($run['results'], fn ($r) => $r === 'W'));
        $losses = count(array_filter($run['results'], fn ($r) => $r === 'L'));

        [$winProbability, $oddsSource] = self::winProbability($deck, $deckVersion);

        return [
            ...$run,
            'wins' => $wins,
            'losses' => $losses,
            'winProbability' => (int) round($winProbability * 100),
            'oddsSource' => $oddsSource,
            'odds' => LeagueFinishOdds::run(
                MtgoMatch::displayFormat($league->format),
                $wins,
                $losses,
                $league->kind->roundCount(),
                $winProbability,
            ),
        ];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private static function winProbability(Deck $deck, ?DeckVersion $deckVersion): array
    {
        $league = MatchRecord::fromQuery(
            $deck->matches()
                ->whereNotNull('matches.league_id')
                ->whereNotNull('matches.outcome')
                ->when($deckVersion, fn ($q) => $q->where('matches.deck_version_id', $deckVersion->id))
                ->getQuery()
        );

        if ($league->total() >= self::MINIMUM_LEAGUE_MATCHES) {
            return [$league->winrate() / 100, 'league'];
        }

        $overall = MatchRecord::fromQuery(
            $deck->matches()
                ->whereNotNull('matches.outcome')
                ->when($deckVersion, fn ($q) => $q->where('matches.deck_version_id', $deckVersion->id))
                ->getQuery()
        );

        if ($overall->total() === 0) {
            return [0.5, 'none'];
        }

        return [$overall->winrate() / 100, 'deck'];
    }
}
