<?php

namespace App\Actions\Matches;

use App\Models\Card;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResolveOpponentColorIdentities
{
    /** WUBRG, so two matches against the same colours always read the same. */
    private const ORDER = ['W', 'U', 'B', 'R', 'G'];

    /**
     * The colours an opponent was seen casting, per match.
     *
     * Limited has no archetype to name a seat with, so the colour pair is the
     * only shorthand a draft opponent has. It is read from the cards that
     * actually appeared (`game_player.deck_json`), which means it can only ever
     * understate: a splash that never showed up is a colour we never saw.
     *
     * Colourless cards contribute nothing — a board of artifacts is not an
     * identity — so a match that revealed only those returns null rather than
     * an empty string, and the caller shows its unknown state.
     *
     * Two queries regardless of how many matches are passed.
     *
     * @param  Collection<int, int>|array<int, int>  $matchIds
     * @return array<int, string> comma-separated identity keyed by match id
     */
    public static function run(Collection|array $matchIds): array
    {
        $ids = collect($matchIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = DB::table('game_player as gp')
            ->join('games as g', 'g.id', '=', 'gp.game_id')
            ->whereIn('g.match_id', $ids)
            ->where('gp.is_local', false)
            ->select('g.match_id', 'gp.deck_json')
            ->get();

        /** @var array<int, array<int, true>> $mtgoIdsByMatch */
        $mtgoIdsByMatch = [];

        foreach ($rows as $row) {
            $cards = json_decode($row->deck_json ?? '', true);

            if (! is_array($cards)) {
                continue;
            }

            foreach ($cards as $card) {
                if (empty($card['mtgo_id'])) {
                    continue;
                }

                $mtgoIdsByMatch[(int) $row->match_id][(int) $card['mtgo_id']] = true;
            }
        }

        if ($mtgoIdsByMatch === []) {
            return [];
        }

        $identityByMtgoId = Card::query()
            ->whereIn('mtgo_id', collect($mtgoIdsByMatch)->flatMap(fn (array $ids) => array_keys($ids))->unique()->all())
            ->pluck('color_identity', 'mtgo_id');

        $resolved = [];

        foreach ($mtgoIdsByMatch as $matchId => $mtgoIds) {
            $symbols = collect(array_keys($mtgoIds))
                ->flatMap(fn (int $mtgoId) => explode(',', (string) $identityByMtgoId->get($mtgoId)))
                ->map(fn (string $symbol) => strtoupper(trim($symbol)))
                ->filter(fn (string $symbol) => in_array($symbol, self::ORDER, true))
                ->unique();

            if ($symbols->isEmpty()) {
                continue;
            }

            $resolved[$matchId] = collect(self::ORDER)
                ->filter(fn (string $symbol) => $symbols->contains($symbol))
                ->implode(',');
        }

        return $resolved;
    }
}
