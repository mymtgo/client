<?php

namespace App\Actions\Limited\Read;

use App\Data\Front\LimitedIndexKpisData;
use App\Data\Front\LimitedIndexRowData;
use App\Enums\DraftState;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildLimitedIndex
{
    /**
     * Rows for the Limited index (limited leagues plus unlinked drafts), the
     * KPI strip over the same filtered set, and the distinct set codes for
     * the filter dropdown.
     *
     * @return array{rows: array<int, LimitedIndexRowData>, kpis: LimitedIndexKpisData, sets: array<int, string>}
     */
    public static function run(?string $set, ?string $kind, Carbon $from, Carbon $to): array
    {
        $leagues = League::query()
            ->limited()
            ->when($set, fn ($q) => $q->where('set_code', $set))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->whereBetween('started_at', [$from, $to])
            ->with([
                'draft' => fn ($q) => $q->withCount(['picks as picks_made_count' => fn ($p) => $p->whereNotNull('picked_catalog_id')]),
                'matches' => fn ($q) => $q->where('state', MatchState::Complete)->withOpponentName()->orderBy('started_at'),
                'deckSnapshots' => fn ($q) => $q->where('source', 'registered'),
            ])
            ->orderByDesc('started_at')
            ->get();

        $unlinked = $kind === LeagueKind::Sealed->value
            ? collect()
            : Draft::query()
                ->whereNull('league_id')
                ->whereBetween('created_at', [$from, $to])
                ->withCount(['picks as picks_made_count' => fn ($p) => $p->whereNotNull('picked_catalog_id')])
                ->orderByDesc('created_at')
                ->get();

        $timings = self::pickTimings($leagues->pluck('draft')->filter()->merge($unlinked));

        $rows = $leagues->map(fn (League $league) => self::leagueRow($league, $timings))
            ->toBase()
            ->merge($unlinked->map(fn (Draft $draft) => self::unlinkedRow($draft, $timings))->toBase())
            ->sortByDesc(fn (LimitedIndexRowData $row) => $row->startedAt ?? '')
            ->values()
            ->all();

        $sets = League::query()->limited()->whereNotNull('set_code')->distinct()->orderBy('set_code')->pluck('set_code')->all();

        return [
            'rows' => $rows,
            'kpis' => self::kpis($leagues, $unlinked, $timings),
            'sets' => $sets,
        ];
    }

    /**
     * Per draft: average seconds from shown_at to picked_at, and the share of
     * picks with two or more reservations.
     *
     * @param  Collection<int, Draft>  $drafts
     * @return array<int, array{avg: int|null, indecisive: int, picks: int}>
     */
    private static function pickTimings(Collection $drafts): array
    {
        if ($drafts->isEmpty()) {
            return [];
        }

        $picks = DraftPick::query()
            ->whereIn('draft_id', $drafts->pluck('id'))
            ->whereNotNull('picked_at')
            ->get(['draft_id', 'shown_at', 'picked_at', 'reservations']);

        return $picks->groupBy('draft_id')->map(function (Collection $group) {
            $seconds = $group
                ->filter(fn (DraftPick $pick) => $pick->shown_at && $pick->picked_at)
                ->map(fn (DraftPick $pick) => max(0, $pick->picked_at->diffInSeconds($pick->shown_at, true)));

            return [
                'avg' => $seconds->isEmpty() ? null : (int) round($seconds->avg()),
                'indecisive' => $group->filter(fn (DraftPick $pick) => count($pick->reservations ?? []) >= 2)->count(),
                'picks' => $group->count(),
            ];
        })->all();
    }

    /**
     * @param  array<int, array{avg: int|null, indecisive: int, picks: int}>  $timings
     */
    private static function leagueRow(League $league, array $timings): LimitedIndexRowData
    {
        $draft = $league->draft;
        $matches = $league->matches;
        $wins = $matches->where('outcome', MatchOutcome::Win)->count();
        $losses = $matches->where('outcome', MatchOutcome::Loss)->count();
        [$state, $variant] = LeagueStateBadge::run($league, $draft, $matches->count());

        return new LimitedIndexRowData(
            leagueId: $league->id,
            draftId: $draft?->id,
            title: trim(($league->set_code ?? '').' '.ucfirst($league->kind->value)),
            setCode: $league->set_code,
            kind: $league->kind->value,
            state: $state,
            stateVariant: $variant,
            startedAt: $league->started_at?->toIso8601String(),
            startedAtHuman: $league->started_at?->format('D j M · H:i'),
            wins: $wins,
            losses: $losses,
            results: self::results($matches),
            picksMade: (int) ($draft?->picks_made_count ?? 0),
            picksExpected: (int) ($draft?->picks_expected ?? 42),
            deckRegistered: $league->deckSnapshots->isNotEmpty(),
            versionCount: $league->deckSnapshots->pluck('signature')->unique()->count(),
            avgPickSeconds: $draft ? ($timings[$draft->id]['avg'] ?? null) : null,
            opponents: $matches->pluck('opponent_name')->filter()->values()->all(),
            note: $matches->isEmpty() && $league->state === LeagueState::Complete ? 'Draft finished, league ended without play' : null,
            linked: true,
        );
    }

    /**
     * @param  array<int, array{avg: int|null, indecisive: int, picks: int}>  $timings
     */
    private static function unlinkedRow(Draft $draft, array $timings): LimitedIndexRowData
    {
        return new LimitedIndexRowData(
            leagueId: null,
            draftId: $draft->id,
            title: 'Draft (league unknown)',
            setCode: null,
            kind: LeagueKind::Draft->value,
            state: $draft->state === DraftState::Abandoned ? 'Draft abandoned' : 'Unlinked',
            stateVariant: 'warning',
            startedAt: ($draft->started_at ?? $draft->created_at)?->toIso8601String(),
            startedAtHuman: ($draft->started_at ?? $draft->created_at)?->format('D j M · H:i'),
            wins: 0,
            losses: 0,
            results: [null, null, null],
            picksMade: (int) $draft->picks_made_count,
            picksExpected: (int) $draft->picks_expected,
            deckRegistered: false,
            versionCount: 0,
            avgPickSeconds: $timings[$draft->id]['avg'] ?? null,
            opponents: [],
            note: 'Will link when a match or pool grant arrives',
            linked: false,
        );
    }

    /**
     * The result dots, padded to the three-match league minimum.
     *
     * @param  Collection<int, MtgoMatch>  $matches
     * @return array<int, 'W'|'L'|null>
     */
    private static function results(Collection $matches): array
    {
        $results = $matches->map(fn (MtgoMatch $match) => match ($match->outcome) {
            MatchOutcome::Win => 'W',
            MatchOutcome::Loss => 'L',
            default => null,
        })->values()->all();

        while (count($results) < 3) {
            $results[] = null;
        }

        return $results;
    }

    /**
     * @param  Collection<int, League>  $leagues
     * @param  Collection<int, Draft>  $unlinked
     * @param  array<int, array{avg: int|null, indecisive: int, picks: int}>  $timings
     */
    private static function kpis(Collection $leagues, Collection $unlinked, array $timings): LimitedIndexKpisData
    {
        $matches = $leagues->flatMap->matches;
        $wins = $matches->where('outcome', MatchOutcome::Win)->count();
        $losses = $matches->where('outcome', MatchOutcome::Loss)->count();
        $decided = $wins + $losses;

        $completed = $leagues->filter(fn (League $league) => $league->state === LeagueState::Complete && $league->matches->isNotEmpty());
        $avgWins = $completed->isEmpty() ? null : round($completed->avg(fn (League $league) => $league->matches->where('outcome', MatchOutcome::Win)->count()), 1);
        $avgLosses = $completed->isEmpty() ? null : round($completed->avg(fn (League $league) => $league->matches->where('outcome', MatchOutcome::Loss)->count()), 1);

        $bySet = $leagues->whereNotNull('set_code')->countBy('set_code')->sortDesc();

        $allTimings = collect($timings);
        $pickTotal = $allTimings->sum('picks');
        $avgPick = $allTimings->pluck('avg')->filter(fn ($value) => $value !== null);

        return new LimitedIndexKpisData(
            events: $leagues->count() + $unlinked->count(),
            drafts: $leagues->filter(fn (League $league) => $league->draft !== null)->count() + $unlinked->count(),
            unlinked: $unlinked->count(),
            matchWinPct: $decided > 0 ? (int) round($wins / $decided * 100) : null,
            matchWins: $wins,
            matchLosses: $losses,
            avgWins: $avgWins,
            avgLosses: $avgLosses,
            completedRuns: $completed->count(),
            mostDraftedSet: $bySet->keys()->first(),
            mostDraftedCount: (int) ($bySet->first() ?? 0),
            avgPickSeconds: $avgPick->isEmpty() ? null : (int) round($avgPick->avg()),
            indecisionPct: $pickTotal > 0 ? (int) round($allTimings->sum('indecisive') / $pickTotal * 100) : null,
        );
    }
}
