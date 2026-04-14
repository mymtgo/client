<?php

namespace App\Actions\Leagues;

use App\Models\League;
use App\Models\MtgoMatch;
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
            ->filter(fn (League $league) => isset($matchesByLeague[$league->id]) && $matchesByLeague[$league->id]->isNotEmpty())
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
            ->select('m.id', 'm.league_id', 'm.outcome', 'm.started_at', 'd.id as deck_id', 'd.name as deck_name', 'd.color_identity as deck_color_identity', 'c.art_crop as deck_cover_art', 'c.local_art_crop as deck_local_cover_art')
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
            ->select('ma.mtgo_match_id', 'p.username', 'a.name as archetype_name')
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

            return [
                'id' => $row->id,
                'result' => $won ? 'W' : 'L',
                'opponentName' => $opp?->username,
                'opponentArchetype' => $opp?->archetype_name,
                'gameResults' => $gameResults,
                'startedAt' => $row->started_at,
                'startedAtHuman' => Carbon::parse($row->started_at)->toLocal()->diffForHumans(),
            ];
        })->values()->all();

        $results = array_map(fn ($m) => $m['result'], $matchData);

        // Pad active leagues to 5 slots
        if ($league->state->value === 'active' && count($results) < 5) {
            while (count($results) < 5) {
                $results[] = null;
            }
        }

        // Compute version label
        $versionLabel = null;
        if ($league->deckVersion) {
            $versionIndex = $league->deckVersion->deck->versions()
                ->where('modified_at', '<=', $league->deckVersion->modified_at)
                ->count();
            $versionLabel = 'v'.$versionIndex;
        }

        return [
            'id' => $league->id,
            'name' => $league->name,
            'format' => MtgoMatch::displayFormat($league->format),
            'phantom' => (bool) $league->phantom,
            'state' => $league->state->value,
            'startedAt' => $league->started_at,
            'startedAtHuman' => $league->started_at ? Carbon::parse($league->started_at)->toLocal()->diffForHumans() : null,
            'deck' => $deck,
            'versionLabel' => $versionLabel,
            'results' => $results,
            'matches' => $matchData,
        ];
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
