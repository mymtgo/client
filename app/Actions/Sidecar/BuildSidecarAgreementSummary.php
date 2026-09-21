<?php

namespace App\Actions\Sidecar;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameFieldDiff;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarTables;

class BuildSidecarAgreementSummary
{
    /** @var list<string> */
    private const GAME_FIELDS = ['on_play', 'game_boundaries', 'game_result'];

    /**
     * Per-field counts for the debug page and, in Plan 3, for aggregate
     * telemetry. No ids, no match content: safe to send as-is.
     *
     * A fixed, small number of aggregate queries regardless of how many
     * games or events exist: this never loops firing a query per game or
     * per event, since the debug page polls every 10 seconds.
     *
     * A game (or match) only counts as `agree` when its sidecar coverage is
     * complete AND every one of its events is verified, and it has no diff
     * row for the field; a complete but unverified game is
     * `sidecar_degraded`, never `agree`, regardless of whether a diff row
     * exists for it. `disagree` is unrestricted: every diff row for the
     * field counts, even for a game or match that's incomplete or degraded
     * by the coarse gate, since a genuine value-level conflict is worth
     * surfacing either way.
     *
     * @return array<string, array{agree: int, disagree: int, sidecar_incomplete: int, sidecar_degraded: int}>
     */
    public static function run(): array
    {
        $summary = [];
        foreach (SidecarAuthorityFlags::FIELDS as $field) {
            $summary[$field] = ['agree' => 0, 'disagree' => 0, 'sidecar_incomplete' => 0, 'sidecar_degraded' => 0];
        }

        // An install whose version-gated migration has not run has no
        // tables to aggregate. The all-zero summary is the honest answer and
        // keeps the debug page rendering instead of 500ing.
        if (! SidecarTables::ready()) {
            return $summary;
        }

        $summary['username'] = self::usernameBucket();

        $gameRows = GameEvent::query()
            ->whereNotNull('game_mtgo_id')
            ->selectRaw('game_mtgo_id, MAX(match_mtgo_id) as match_mtgo_id,
                MAX(CASE WHEN type = ? THEN 1 ELSE 0 END) as has_start,
                MAX(CASE WHEN type = ? THEN 1 ELSE 0 END) as has_end,
                MIN(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as all_verified', ['game_started', 'game_ended'])
            ->groupBy('game_mtgo_id')
            ->get();

        if ($gameRows->isEmpty()) {
            return $summary;
        }

        $gameDbIds = Game::query()
            ->whereIn('mtgo_id', $gameRows->pluck('game_mtgo_id'))
            ->pluck('id', 'mtgo_id');

        $incomplete = 0;
        $degraded = 0;
        $eligibleGameDbIds = [];
        $gamesByMatch = [];

        foreach ($gameRows as $row) {
            $complete = (bool) $row->has_start && (bool) $row->has_end;
            $verified = (bool) $row->all_verified;

            if (! $complete) {
                $incomplete++;
            } elseif (! $verified) {
                $degraded++;
            } elseif (isset($gameDbIds[$row->game_mtgo_id])) {
                $eligibleGameDbIds[] = $gameDbIds[$row->game_mtgo_id];
            }

            if ($row->match_mtgo_id !== null) {
                $gamesByMatch[$row->match_mtgo_id][] = $complete;
            }
        }

        // `disagree` is every diff row for the field: a value-level conflict
        // is worth surfacing even for a game that's incomplete or degraded
        // by the coarse hasStart/hasEnd/verified gate (e.g. on_play only
        // needs hasStart, so it can disagree on a game missing game_ended).
        // `agree` is narrower: only eligible (complete + verified) games
        // that have no diff row for the field count as agreeing.
        $gameDiffCounts = GameFieldDiff::query()
            ->whereIn('field', self::GAME_FIELDS)
            ->selectRaw('field, count(*) as n')
            ->groupBy('field')
            ->pluck('n', 'field');

        $eligibleGameDiffCounts = GameFieldDiff::query()
            ->whereIn('field', self::GAME_FIELDS)
            ->whereIn('game_id', $eligibleGameDbIds)
            ->selectRaw('field, count(*) as n')
            ->groupBy('field')
            ->pluck('n', 'field');

        foreach (self::GAME_FIELDS as $field) {
            $eligibleDisagree = (int) ($eligibleGameDiffCounts[$field] ?? 0);
            $summary[$field] = [
                'agree' => max(0, count($eligibleGameDbIds) - $eligibleDisagree),
                'disagree' => (int) ($gameDiffCounts[$field] ?? 0),
                'sidecar_incomplete' => $incomplete,
                'sidecar_degraded' => $degraded,
            ];
        }

        $summary['match_result'] = self::matchResultBucket($gamesByMatch);

        return $summary;
    }

    /** @param  array<int|string, list<bool>>  $gamesByMatch  match_mtgo_id => list of "game complete" flags */
    private static function matchResultBucket(array $gamesByMatch): array
    {
        if ($gamesByMatch === []) {
            return ['agree' => 0, 'disagree' => 0, 'sidecar_incomplete' => 0, 'sidecar_degraded' => 0];
        }

        $matchMtgoIds = array_keys($gamesByMatch);

        $matchEndedIds = GameEvent::query()
            ->where('type', 'match_ended')
            ->whereIn('match_mtgo_id', $matchMtgoIds)
            ->distinct()
            ->pluck('match_mtgo_id');

        // "Any unverified event" is a match-wide signal: it includes
        // match-level events (match_started, match_ended, probe, ...) as
        // well as the game-level events already folded into $gamesByMatch.
        $matchVerified = GameEvent::query()
            ->whereIn('match_mtgo_id', $matchMtgoIds)
            ->selectRaw('match_mtgo_id, MIN(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as all_verified')
            ->groupBy('match_mtgo_id')
            ->pluck('all_verified', 'match_mtgo_id');

        $matchDbIds = MtgoMatch::query()->whereIn('mtgo_id', $matchMtgoIds)->pluck('id', 'mtgo_id');

        $incomplete = 0;
        $degraded = 0;
        $eligibleMatchDbIds = [];

        foreach ($gamesByMatch as $matchMtgoId => $gameCompleteFlags) {
            $allGamesComplete = ! in_array(false, $gameCompleteFlags, true);
            $ended = $matchEndedIds->contains($matchMtgoId);
            $allVerified = (bool) ($matchVerified[$matchMtgoId] ?? false);

            if (! $ended || ! $allGamesComplete) {
                $incomplete++;
            } elseif (! $allVerified) {
                $degraded++;
            } elseif (isset($matchDbIds[$matchMtgoId])) {
                $eligibleMatchDbIds[] = $matchDbIds[$matchMtgoId];
            }
        }

        $disagree = GameFieldDiff::query()
            ->where('field', 'match_result')
            ->whereNull('game_id')
            ->count();

        $eligibleDisagree = GameFieldDiff::query()
            ->where('field', 'match_result')
            ->whereNull('game_id')
            ->whereIn('match_id', $eligibleMatchDbIds)
            ->count();

        return [
            'agree' => max(0, count($eligibleMatchDbIds) - $eligibleDisagree),
            'disagree' => $disagree,
            'sidecar_incomplete' => $incomplete,
            'sidecar_degraded' => $degraded,
        ];
    }

    /** @return array{agree: int, disagree: int, sidecar_incomplete: int, sidecar_degraded: int} */
    private static function usernameBucket(): array
    {
        $counts = GameEvent::query()
            ->where('type', 'probe')
            ->selectRaw('SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified_count,
                SUM(CASE WHEN verified = 0 THEN 1 ELSE 0 END) as unverified_count')
            ->first();

        return [
            'agree' => (int) ($counts->verified_count ?? 0),
            'disagree' => 0,
            'sidecar_incomplete' => 0,
            'sidecar_degraded' => (int) ($counts->unverified_count ?? 0),
        ];
    }
}
