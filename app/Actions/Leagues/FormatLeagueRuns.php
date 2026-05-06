<?php

namespace App\Actions\Leagues;

use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\Leagues\LeagueEvTable;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FormatLeagueRuns
{
    /**
     * Format a collection of leagues into display-ready run data.
     *
     * @param  Collection<int, League>  $leagues  Leagues with deckVersion.deck eager loaded
     * @param  int|null  $accountId  Filter matches by account (null = all)
     * @param  int|null  $deckId  Filter matches to a specific deck (null = all)
     * @return array<int, array>
     */
    public static function run(Collection $leagues, ?int $accountId = null, ?int $deckId = null): array
    {
        if ($leagues->isEmpty()) {
            return [];
        }

        $leagueIds = $leagues->pluck('id');

        $matchRows = self::getMatchRows($leagueIds, $accountId, $deckId);
        $gameRecords = self::getGameRecords($matchRows->pluck('id'));
        $opponentByMatch = self::getOpponentsByMatch($matchRows->pluck('id'));
        $matchesByLeague = $matchRows->groupBy('league_id');

        return $leagues
            ->values()
            ->map(fn (League $league) => self::formatRun($league, $matchesByLeague[$league->id] ?? collect(), $opponentByMatch, $gameRecords))
            ->values()
            ->all();
    }

    private static function getMatchRows(Collection $leagueIds, ?int $accountId, ?int $deckId): Collection
    {
        return DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->leftJoin('cards as c', 'c.id', '=', 'd.cover_id')
            ->whereIn('m.league_id', $leagueIds)
            ->where('m.state', 'complete')
            ->when($accountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->when($deckId, fn ($q, $id) => $q->where('d.id', $id))
            ->select('m.id', 'm.league_id', 'm.outcome', 'm.started_at', 'm.ended_at', 'm.notes', 'd.id as deck_id', 'd.name as deck_name', 'd.color_identity as deck_color_identity', 'c.art_crop as deck_cover_art', 'c.local_art_crop as deck_local_cover_art')
            ->orderBy('m.started_at')
            ->get();
    }

    /**
     * Get individual game results with on_play status, grouped by match.
     *
     * @return Collection<int, Collection> match_id => collection of game rows (ordered by started_at)
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
            ->select('ma.mtgo_match_id', 'p.username', 'ma.archetype_id', 'a.name as archetype_name')
            ->get()
            ->keyBy('mtgo_match_id');

        // Fallback: opponent name from game_player for matches missing archetypes
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
                        $row->archetype_id = null;
                        $opponentByMatch[$row->mtgo_match_id] = $row;
                    }
                });
        }

        return $opponentByMatch;
    }

    private static function formatRun(League $league, Collection $matches, Collection $opponentByMatch, Collection $gameRecords): array
    {
        // Prefer league's direct deck version; fall back to most common deck in matches
        if ($league->deck_version_id && $league->deckVersion?->deck) {
            $deckModel = $league->deckVersion->deck;
            $coverArtUrl = $deckModel->cover?->art_crop_url;
            $deck = ['id' => $deckModel->id, 'name' => $deckModel->name, 'colorIdentity' => $deckModel->color_identity, 'coverArt' => $coverArtUrl, 'coverArtBase64' => self::toBase64($deckModel->cover?->art_crop, $deckModel->cover?->local_art_crop)];
        } else {
            $topRow = $matches->groupBy('deck_id')->map->count()->sortDesc()->keys()
                ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
                ->first();

            $coverArtUrl = $topRow ? self::resolveArtCrop($topRow->deck_cover_art, $topRow->deck_local_cover_art) : null;

            $deck = $matches->groupBy('deck_id')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
                ->map(fn ($row) => [
                    'id' => $row->deck_id,
                    'name' => $row->deck_name,
                    'colorIdentity' => $row->deck_color_identity,
                    'coverArt' => self::resolveArtCrop($row->deck_cover_art, $row->deck_local_cover_art),
                    'coverArtBase64' => self::toBase64($row->deck_cover_art, $row->deck_local_cover_art),
                ])
                ->first();
        }

        $matchData = $matches->map(function ($row) use ($opponentByMatch, $gameRecords) {
            $opp = $opponentByMatch[$row->id] ?? null;
            $won = $row->outcome === 'win';
            $games = $gameRecords->get($row->id, collect());

            // Build per-game results with on_play status
            $gameResults = $games->values()->map(fn ($g) => [
                'result' => (bool) $g->won ? 'W' : 'L',
                'onPlay' => $g->on_play !== null ? (bool) $g->on_play : null,
            ])->all();

            $durationSeconds = ($row->started_at && $row->ended_at)
                ? (int) abs(Carbon::parse($row->ended_at)->diffInSeconds(Carbon::parse($row->started_at)))
                : null;

            return [
                'id' => $row->id,
                'result' => $won ? 'W' : 'L',
                'opponentName' => $opp?->username,
                'opponentArchetype' => $opp?->archetype_name,
                'opponentArchetypeId' => $opp?->archetype_id !== null ? (int) $opp->archetype_id : null,
                'gameResults' => $gameResults,
                'startedAt' => $row->started_at,
                'startedAtHuman' => Carbon::parse($row->started_at)->toLocal()->diffForHumans(),
                'durationSeconds' => $durationSeconds,
                'notes' => $row->notes ?? null,
            ];
        })->values()->all();

        $results = array_map(fn ($m) => $m['result'], $matchData);

        // Pad active leagues to 5 slots
        if ($league->state->value === 'active' && count($results) < 5) {
            while (count($results) < 5) {
                $results[] = null;
            }
        }

        // Compute version label — deck can be null when soft-deleted (see line 129).
        $versionLabel = null;
        if ($league->deckVersion?->deck) {
            $versionIndex = $league->deckVersion->deck->versions()
                ->where('modified_at', '<=', $league->deckVersion->modified_at)
                ->count();
            $versionLabel = 'v'.$versionIndex;
        }

        $classification = self::classifyRun($league, $matches);
        $stats = self::computeStats($matches, $opponentByMatch, $gameRecords);
        $tixDelta = self::tixDelta($league, $matches);

        return [
            'id' => $league->id,
            'name' => $league->name,
            'format' => MtgoMatch::displayFormat($league->format),
            'state' => $league->state->value,
            'startedAt' => $league->started_at,
            'startedAtHuman' => $league->started_at ? Carbon::parse($league->started_at)->toLocal()->diffForHumans() : null,
            'droppedAt' => $league->dropped_at,
            'droppedAtHuman' => $league->dropped_at ? Carbon::parse($league->dropped_at)->toLocal()->diffForHumans() : null,
            'deck' => $deck,
            'versionLabel' => $versionLabel,
            'results' => $results,
            'matches' => $matchData,
            'notes' => $league->notes,
            'classification' => $classification['classification'],
            'liveRound' => $classification['liveRound'],
            'avgMatchSeconds' => self::avgMatchSeconds($matches),
            'timeOfDay' => self::timeOfDay($matches),
            'topOpponentArchetype' => $stats['topMatchups'][0]['archetype'] ?? null,
            'gameWins' => $stats['gameWins'],
            'gameLosses' => $stats['gameLosses'],
            'onPlayRecord' => $stats['onPlayRecord'],
            'onDrawRecord' => $stats['onDrawRecord'],
            'topMatchups' => $stats['topMatchups'],
            'tixDelta' => $tixDelta,
        ];
    }

    /**
     * @return array{classification: string, liveRound: int|null}
     */
    private static function classifyRun(League $league, Collection $matches): array
    {
        $wins = $matches->where('outcome', 'win')->count();
        $state = $league->state->value;

        if ($state === 'active') {
            return ['classification' => 'LIVE', 'liveRound' => $matches->count() + 1];
        }

        if ($state === 'dropped') {
            return ['classification' => 'BRICK', 'liveRound' => null];
        }

        if ($wins === 5) {
            return ['classification' => 'TROPHY', 'liveRound' => null];
        }

        if ($wins === 4) {
            return ['classification' => 'CASH', 'liveRound' => null];
        }

        return ['classification' => 'FINISH', 'liveRound' => null];
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

    private static function timeOfDay(Collection $matches): ?string
    {
        if ($matches->isEmpty()) {
            return null;
        }

        $buckets = $matches->map(function ($m) {
            $hour = (int) Carbon::parse($m->started_at)->toLocal()->format('G');

            return match (true) {
                $hour >= 6 && $hour < 12 => 'morning',
                $hour >= 12 && $hour < 17 => 'afternoon',
                $hour >= 17 && $hour < 22 => 'evening',
                default => 'night',
            };
        });

        return $buckets->countBy()->sortDesc()->keys()->first();
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

    private static function tixDelta(League $league, Collection $matches): ?float
    {
        $wins = $matches->where('outcome', 'win')->count();
        $losses = $matches->where('outcome', 'loss')->count();
        $state = $league->state->value;

        $format = MtgoMatch::displayFormat($league->format);

        if ($state === 'complete') {
            return LeagueEvTable::netTix($format, $wins, $losses);
        }

        if ($state === 'dropped') {
            $paddedLosses = max($losses, 5 - $wins);

            return LeagueEvTable::netTix($format, $wins, $paddedLosses);
        }

        return null;
    }

    private static function resolveArtCrop(?string $artCrop, ?string $localArtCrop): ?string
    {
        return $localArtCrop ? Storage::disk('cards')->url($localArtCrop) : $artCrop;
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
