<?php

namespace App\Actions\Leagues;

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\Leagues\LeagueEvTable;
use Carbon\Carbon;

class GetDeckLeaguePnl
{
    /**
     * What this deck's league runs have cost and returned.
     *
     * Tix are not recorded anywhere: MTGO does not report a prize in the
     * logs, so the figures are the published prize structure applied to the
     * finishing record of each run. That makes them exact for a completed
     * run and absent for a run still being played, which is why an active
     * league is left out rather than counted as a brick.
     *
     * Limited runs have no priced structure, so the whole panel reports
     * itself unsupported rather than showing zeroes that look like losses.
     *
     * @return array{supported: bool, entries: int, tixSpent: float, tixReturned: float, net: float, perEntry: float, trophies: int, avgWins: float, breakEvenWins: float|null}
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to, ?DeckVersion $deckVersion = null): array
    {
        $format = MtgoMatch::displayFormat($deck->format);
        $entryCost = LeagueEvTable::entryCost($format);

        if ($entryCost === null) {
            return [
                'supported' => false,
                'entries' => 0,
                'tixSpent' => 0.0,
                'tixReturned' => 0.0,
                'net' => 0.0,
                'perEntry' => 0.0,
                'trophies' => 0,
                'avgWins' => 0.0,
                'breakEvenWins' => null,
            ];
        }

        $leagues = League::query()
            ->whereBetween('started_at', [$from, $to])
            ->whereHas('matches', function ($q) use ($deck, $deckVersion) {
                $q->whereHas('deckVersion', fn ($dv) => $dv->where('deck_id', $deck->id))
                    ->when($deckVersion, fn ($m) => $m->where('matches.deck_version_id', $deckVersion->id));
            })
            ->withCount([
                'matches as wins_count' => fn ($q) => $q->where('outcome', 'win'),
                'matches as losses_count' => fn ($q) => $q->where('outcome', 'loss'),
            ])
            ->get();

        $entries = 0;
        $net = 0.0;
        $wins = 0;
        $trophies = 0;

        foreach ($leagues as $league) {
            $delta = LeagueEvTable::netTixForRun(
                MtgoMatch::displayFormat($league->format),
                $league->state->value,
                $league->wins_count,
                $league->losses_count,
            );

            if ($delta === null) {
                continue;
            }

            $entries++;
            $net += $delta;
            $wins += $league->wins_count;

            if ($league->wins_count >= $league->kind->roundCount()) {
                $trophies++;
            }
        }

        $tixSpent = $entries * $entryCost;
        $net = round($net, 2);

        return [
            'supported' => true,
            'entries' => $entries,
            'tixSpent' => $tixSpent,
            'tixReturned' => round($tixSpent + $net, 2),
            'net' => $net,
            'perEntry' => $entries > 0 ? round($net / $entries, 2) : 0.0,
            'trophies' => $trophies,
            'avgWins' => $entries > 0 ? round($wins / $entries, 1) : 0.0,
            'breakEvenWins' => LeagueEvTable::breakEvenWins($format, 5),
        ];
    }
}
