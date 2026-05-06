<?php

namespace App\Actions\Tournaments;

use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FormatTournamentRuns
{
    /**
     * Format a collection of tournaments into display-ready run data.
     *
     * @param  Collection<int, Tournament>  $tournaments
     */
    public static function run(Collection $tournaments, Deck $deck): array
    {
        if ($tournaments->isEmpty()) {
            return [];
        }

        $tournamentIds = $tournaments->pluck('id');

        $matchRows = self::getMatchRows($tournamentIds, $deck->id);
        $gameRecords = self::getGameRecords($matchRows->pluck('id'));
        $opponentByMatch = self::getOpponentsByMatch($matchRows->pluck('id'));
        $matchesByTournament = $matchRows->groupBy('tournament_id');

        // Pre-compute version index map: deck_version_id => index (1-based,
        // sorted by modified_at ascending). One pass per request, not per
        // tournament — avoids N+1 across the version list.
        $versionIndexByVersionId = $deck->versions
            ->sortBy('modified_at')
            ->values()
            ->mapWithKeys(fn ($v, $i) => [$v->id => $i + 1]);

        $deckCard = self::buildDeckCard($deck);

        return $tournaments
            ->values()
            ->map(fn (Tournament $tournament) => self::formatRun(
                $tournament,
                $matchesByTournament[$tournament->id] ?? collect(),
                $opponentByMatch,
                $gameRecords,
                $deckCard,
                $versionIndexByVersionId,
            ))
            ->values()
            ->all();
    }

    private static function getMatchRows(Collection $tournamentIds, int $deckId): Collection
    {
        return DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->whereIn('m.tournament_id', $tournamentIds)
            ->where('dv.deck_id', $deckId)
            ->select(
                'm.id',
                'm.tournament_id',
                'm.outcome',
                'm.state',
                'm.started_at',
                'm.ended_at',
                'm.tournament_round',
                'm.deck_version_id',
            )
            ->orderBy('m.started_at')
            ->get();
    }

    /**
     * @return Collection<int, Collection> match_id => collection of game rows
     */
    private static function getGameRecords(Collection $matchIds): Collection
    {
        if ($matchIds->isEmpty()) {
            return collect();
        }

        return DB::table('games as g')
            ->leftJoin('game_player as gp', function ($join) {
                $join->on('gp.game_id', '=', 'g.id')
                    ->where('gp.is_local', true);
            })
            ->whereIn('g.match_id', $matchIds)
            ->whereNotNull('g.won')
            ->select('g.match_id', 'g.won', 'g.started_at', 'gp.on_play')
            ->orderBy('g.started_at')
            ->get()
            ->groupBy('match_id');
    }

    private static function getOpponentsByMatch(Collection $matchIds): Collection
    {
        if ($matchIds->isEmpty()) {
            return collect();
        }

        $opponentByMatch = DB::table('match_archetypes as ma')
            ->join('players as p', 'p.id', '=', 'ma.player_id')
            ->leftJoin('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereIn('ma.mtgo_match_id', $matchIds)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereRaw('g.match_id = ma.mtgo_match_id')
                    ->whereRaw('gp.player_id = ma.player_id')
                    ->where('gp.is_local', false);
            })
            ->select('ma.mtgo_match_id', 'p.username', 'a.name as archetype_name')
            ->get()
            ->keyBy('mtgo_match_id');

        $missingIds = $matchIds->diff($opponentByMatch->keys());
        if ($missingIds->isNotEmpty()) {
            DB::table('game_player as gp')
                ->join('games as g', 'g.id', '=', 'gp.game_id')
                ->join('players as p', 'p.id', '=', 'gp.player_id')
                ->whereIn('g.match_id', $missingIds)
                ->where('gp.is_local', false)
                ->select('g.match_id as mtgo_match_id', 'p.username')
                ->groupBy('g.match_id', 'p.username')
                ->get()
                ->each(function ($row) use ($opponentByMatch) {
                    if (! $opponentByMatch->has($row->mtgo_match_id)) {
                        $row->archetype_name = null;
                        $opponentByMatch[$row->mtgo_match_id] = $row;
                    }
                });
        }

        return $opponentByMatch;
    }

    private static function buildDeckCard(Deck $deck): array
    {
        $cover = $deck->cover;
        $coverArtUrl = $cover?->art_crop_url;

        return [
            'id' => $deck->id,
            'name' => $deck->name,
            'colorIdentity' => $deck->color_identity,
            'coverArt' => $coverArtUrl,
            'coverArtBase64' => self::toBase64($cover?->art_crop, $cover?->local_art_crop),
        ];
    }

    private static function formatRun(
        Tournament $tournament,
        Collection $matches,
        Collection $opponentByMatch,
        Collection $gameRecords,
        array $deckCard,
        Collection $versionIndexByVersionId,
    ): array {
        $matchData = $matches->map(function ($row) use ($opponentByMatch, $gameRecords) {
            $opp = $opponentByMatch[$row->id] ?? null;
            $isComplete = $row->state === MatchState::Complete->value;
            $games = $gameRecords->get($row->id, collect());

            $gameResults = $games->values()->map(fn ($g) => [
                'result' => (bool) $g->won ? 'W' : 'L',
                'onPlay' => $g->on_play !== null ? (bool) $g->on_play : null,
            ])->all();

            $durationSeconds = ($row->started_at && $row->ended_at)
                ? (int) abs(Carbon::parse($row->ended_at)->diffInSeconds(Carbon::parse($row->started_at)))
                : null;

            return [
                'id' => $row->id,
                'state' => $row->state,
                'result' => $isComplete ? ($row->outcome === 'win' ? 'W' : 'L') : null,
                'opponentName' => $opp?->username,
                'opponentArchetype' => $opp?->archetype_name,
                'gameResults' => $gameResults,
                'startedAt' => $row->started_at,
                'startedAtHuman' => Carbon::parse($row->started_at)->toLocal()->diffForHumans(),
                'durationSeconds' => $durationSeconds,
                'roundNumber' => $row->tournament_round !== null ? (int) $row->tournament_round : null,
            ];
        })->values()->all();

        $completed = $matches->where('state', MatchState::Complete->value);
        $results = $completed->map(fn ($r) => $r->outcome === 'win' ? 'W' : 'L')->values()->all();

        // versionLabel: reflect span of deck versions used across matches in
        // this tournament. Single version → "v3"; multiple → "v3–v5".
        $versionLabel = self::computeVersionLabel($matches, $versionIndexByVersionId);

        $stats = self::computeStats($completed, $opponentByMatch, $gameRecords);

        return [
            'id' => $tournament->id,
            'name' => $tournament->name,
            'format' => MtgoMatch::displayFormat($tournament->format),
            'mtgo_event_id' => $tournament->mtgo_event_id,
            'startedAt' => $tournament->started_at,
            'startedAtHuman' => $tournament->started_at ? Carbon::parse($tournament->started_at)->toLocal()->diffForHumans() : null,
            'deck' => $deckCard,
            'versionLabel' => $versionLabel,
            'results' => $results,
            'matches' => $matchData,
            'avgMatchSeconds' => self::avgMatchSeconds($completed),
            'topOpponentArchetype' => $stats['topMatchups'][0]['archetype'] ?? null,
            'gameWins' => $stats['gameWins'],
            'gameLosses' => $stats['gameLosses'],
            'onPlayRecord' => $stats['onPlayRecord'],
            'onDrawRecord' => $stats['onDrawRecord'],
            'topMatchups' => $stats['topMatchups'],
            'matches_count' => $matches->count(),
            'inProgressCount' => $matches->count() - $completed->count(),
            'wins' => $completed->where('outcome', 'win')->count(),
            'losses' => $completed->where('outcome', 'loss')->count(),
            'name_synthesized' => $tournament->name_synthesized,
        ];
    }

    private static function computeVersionLabel(Collection $matches, Collection $versionIndexByVersionId): ?string
    {
        $indexes = $matches
            ->pluck('deck_version_id')
            ->filter()
            ->unique()
            ->map(fn ($id) => $versionIndexByVersionId->get($id))
            ->filter()
            ->sort()
            ->values();

        if ($indexes->isEmpty()) {
            return null;
        }

        $min = $indexes->first();
        $max = $indexes->last();

        return $min === $max ? "v{$min}" : "v{$min}–v{$max}";
    }

    private static function avgMatchSeconds(Collection $matches): ?int
    {
        $durations = $matches
            ->filter(fn ($m) => $m->started_at && $m->ended_at)
            ->map(fn ($m) => (int) abs(Carbon::parse($m->ended_at)->diffInSeconds(Carbon::parse($m->started_at))));

        if ($durations->isEmpty()) {
            return null;
        }

        return (int) round($durations->avg());
    }

    /**
     * @return array{
     *   gameWins: int,
     *   gameLosses: int,
     *   onPlayRecord: array{wins: int, losses: int},
     *   onDrawRecord: array{wins: int, losses: int},
     *   topMatchups: array<int, array{archetype: string, wins: int, losses: int}>
     * }
     */
    private static function computeStats(Collection $matches, Collection $opponentByMatch, Collection $gameRecords): array
    {
        $allGames = $matches->flatMap(fn ($row) => $gameRecords->get($row->id, collect()));

        $gameWins = $allGames->where('won', 1)->count();
        $gameLosses = $allGames->where('won', 0)->count();

        $onPlayWins = $allGames->where('on_play', 1)->where('won', 1)->count();
        $onPlayLosses = $allGames->where('on_play', 1)->where('won', 0)->count();
        $onDrawWins = $allGames->where('on_play', 0)->where('won', 1)->count();
        $onDrawLosses = $allGames->where('on_play', 0)->where('won', 0)->count();

        $archMatches = $matches
            ->map(function ($row) use ($opponentByMatch) {
                $opp = $opponentByMatch[$row->id] ?? null;

                return [
                    'archetype' => $opp?->archetype_name,
                    'won' => $row->outcome === 'win',
                ];
            })
            ->filter(fn ($m) => $m['archetype'] !== null);

        $topMatchups = $archMatches
            ->groupBy('archetype')
            ->map(fn ($rows, $name) => [
                'archetype' => $name,
                'wins' => $rows->where('won', true)->count(),
                'losses' => $rows->where('won', false)->count(),
                'count' => $rows->count(),
            ])
            ->sortByDesc(fn ($r) => $r['count'] * 1000 + ($r['count'] > 0 ? ($r['wins'] / $r['count']) : 0))
            ->values()
            ->take(3)
            ->map(fn ($r) => ['archetype' => $r['archetype'], 'wins' => $r['wins'], 'losses' => $r['losses']])
            ->all();

        return [
            'gameWins' => $gameWins,
            'gameLosses' => $gameLosses,
            'onPlayRecord' => ['wins' => $onPlayWins, 'losses' => $onPlayLosses],
            'onDrawRecord' => ['wins' => $onDrawWins, 'losses' => $onDrawLosses],
            'topMatchups' => $topMatchups,
        ];
    }

    private static function toBase64(?string $url, ?string $localStoragePath = null): ?string
    {
        if (! $url && ! $localStoragePath) {
            return null;
        }

        try {
            if ($localStoragePath && Storage::disk('cards')->exists($localStoragePath)) {
                $contents = Storage::disk('cards')->get($localStoragePath);
            } else {
                if (! $url) {
                    return null;
                }

                $contents = file_get_contents($url);
            }

            if ($contents === false || $contents === null) {
                return null;
            }

            $mime = 'image/jpeg';
            $source = $localStoragePath ?? $url ?? '';
            if (str_contains($source, '.png')) {
                $mime = 'image/png';
            } elseif (str_contains($source, '.webp')) {
                $mime = 'image/webp';
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return null;
        }
    }
}
