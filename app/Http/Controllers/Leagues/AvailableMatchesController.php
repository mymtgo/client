<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailableMatchesController extends Controller
{
    public function __invoke(League $league): JsonResponse
    {
        if (! $league->manual) {
            throw ValidationException::withMessages(['league' => 'League is not manual.']);
        }

        $deckId = DeckVersion::query()->whereKey($league->deck_version_id)->value('deck_id');

        if ($deckId === null) {
            return response()->json([]);
        }

        $versionIds = DeckVersion::query()->where('deck_id', $deckId)->pluck('id');

        $matches = MtgoMatch::query()
            ->whereIn('deck_version_id', $versionIds)
            ->whereNull('league_id')
            ->where('state', MatchState::Complete)
            ->withGameCounts()
            ->withOpponentName()
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        $opponentArchetypes = DB::table('match_archetypes as ma')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereIn('ma.mtgo_match_id', $matches->pluck('id'))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereRaw('g.match_id = ma.mtgo_match_id')
                    ->whereRaw('gp.player_id = ma.player_id')
                    ->where('gp.is_local', false);
            })
            ->select('ma.mtgo_match_id', 'a.name')
            ->get()
            ->keyBy('mtgo_match_id');

        $payload = $matches->map(function (MtgoMatch $m) use ($opponentArchetypes) {
            $won = $m->isWin();
            $lost = $m->isLoss();

            return [
                'id' => $m->id,
                'startedAt' => $m->started_at?->toIso8601String(),
                'startedAtHuman' => $m->started_at?->diffForHumans(),
                'endedAt' => $m->ended_at?->toIso8601String(),
                'result' => $won ? 'W' : ($lost ? 'L' : null),
                'opponentName' => $m->opponent_name ?? null,
                'opponentArchetype' => $opponentArchetypes->get($m->id)?->name,
                'gameRecord' => $m->gameRecord(),
            ];
        })->values();

        return response()->json($payload);
    }
}
