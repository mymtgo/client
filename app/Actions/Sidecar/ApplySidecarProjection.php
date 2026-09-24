<?php

namespace App\Actions\Sidecar;

use App\Enums\MatchState;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameFieldDiff;
use App\Models\MtgoMatch;
use App\Sidecar\Resolution;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarGameView;
use App\Sidecar\SidecarMatchView;
use App\Sidecar\SidecarTables;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ApplySidecarProjection
{
    /**
     * How far each game boundary may drift between the two sources before
     * it counts as a disagreement. The log stamps whole seconds, taken from
     * the state line MTGO happens to write; the sidecar stamps the
     * in-process event itself. A second or two of drift is the normal case,
     * not a conflict, and treating it as one would peg the debug page's
     * agreement percentage for this field near zero forever.
     */
    public const BOUNDARY_TOLERANCE_SECONDS = 5;

    /**
     * Runs after the log projection for a match. Never changes match state.
     * Sidecar-only fields (clock) are written unconditionally; shared fields
     * go through ResolveFieldAuthority and land in game_field_diffs when the
     * two sources disagree.
     */
    public static function run(MtgoMatch $match): void
    {
        if (! SidecarTables::ready()) {
            return;
        }

        $view = BuildSidecarMatchView::run((string) $match->mtgo_id);

        if ($view === null) {
            return;
        }

        $flags = SidecarAuthorityFlags::current();
        $games = $match->games()->with('players')->get()->keyBy(fn (Game $g) => (string) $g->mtgo_id);

        // Per-game eligibility, keyed by game mtgo id: complete, verified and
        // cross-checked coverage for the games the sidecar actually saw. A DB
        // game that never appears here (crash mid-match, late attach) is
        // treated as ineligible below, not silently skipped.
        $gameEligible = [];

        foreach ($view->games as $gameMtgoId => $gameView) {
            $game = $games->get((string) $gameMtgoId);

            if ($game === null) {
                continue;
            }

            // Replay frames: no gate. When the sidecar saw the game, its
            // frames replace the log's wholesale (spec 2026-09-23 section 2).
            ProjectSidecarTimeline::run($game, $gameView);

            $cross = CrossCheckSidecarGame::run($game, $gameView);
            $crossOk = $cross->passed && $cross->compared > 0;
            $gameEligible[(string) $gameMtgoId] = $gameView->coverageComplete() && $gameView->allVerified && $crossOk;

            self::writeClock($game, $gameView);
            self::resolveGame($match, $game, $gameView, $flags, $crossOk);
        }

        // The match-result gate requires every DB game on the match to be
        // eligible, not merely every game the sidecar happened to report. A
        // game the sidecar never covered at all must fail the gate rather
        // than being invisible to it.
        $allGamesEligible = $games->isNotEmpty()
            && $games->keys()->every(fn (string $id) => $gameEligible[$id] ?? false);

        self::resolveMatch($match, $games, $view, $flags, $allGamesEligible);

        GameEvent::where('match_mtgo_id', $match->mtgo_id)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    private static function writeClock(Game $game, SidecarGameView $view): void
    {
        foreach ($view->clockByName as $name => $clock) {
            $player = $game->players->firstWhere('username', $name);

            if ($player === null) {
                continue;
            }

            $pivot = $player->pivot;
            $unchanged = $pivot->clock_remaining_ms_start === $clock['start']
                && $pivot->clock_remaining_ms_end === $clock['end']
                && $pivot->clock_remaining_ms_min === $clock['min']
                && $pivot->sideboard_ms_used === $clock['sideboard_used'];

            if ($unchanged) {
                continue;
            }

            $game->players()->updateExistingPivot($player->id, [
                'clock_remaining_ms_start' => $clock['start'],
                'clock_remaining_ms_end' => $clock['end'],
                'clock_remaining_ms_min' => $clock['min'],
                'sideboard_ms_used' => $clock['sideboard_used'],
            ]);
        }
    }

    /** @param  array<string, bool>  $flags */
    private static function resolveGame(MtgoMatch $match, Game $game, SidecarGameView $view, array $flags, bool $crossOk): void
    {
        $local = $game->players->firstWhere('pivot.is_local', true);
        $opponent = $game->players->firstWhere('pivot.is_local', false);
        $logOnPlay = $game->players->firstWhere('pivot.on_play', true)?->username;
        $logWinner = $game->won === null || $local === null
            ? null
            : ($game->won ? $local->username : $opponent?->username);

        $logBoundaries = $game->started_at
            ? ['started_at' => $game->started_at->toIso8601ZuluString(), 'ended_at' => $game->ended_at?->toIso8601ZuluString()]
            : null;
        $sidecarBoundaries = $view->startedAt
            ? ['started_at' => $view->startedAt->toIso8601ZuluString(), 'ended_at' => $view->endedAt?->toIso8601ZuluString()]
            : null;
        $logResult = $logWinner ? ['winner' => $logWinner] : null;
        $sidecarResult = $view->winnerName ? ['winner' => $view->winnerName] : null;

        $logValues = ['on_play' => $logOnPlay, 'game_boundaries' => $logBoundaries, 'game_result' => $logResult];
        $sidecarValues = ['on_play' => $view->onPlayName, 'game_boundaries' => $sidecarBoundaries, 'game_result' => $sidecarResult];

        $resolutions = [
            'on_play' => ResolveFieldAuthority::run('on_play', $logOnPlay, $view->onPlayName, $flags['on_play'], $view->hasStart, $view->allVerified, $crossOk),
            'game_boundaries' => self::resolveBoundaries($logBoundaries, $sidecarBoundaries, $flags, $view, $crossOk),
            'game_result' => ResolveFieldAuthority::run('game_result', $logResult, $sidecarResult, $flags['game_result'], $view->hasEnd, $view->allVerified, $crossOk),
        ];

        foreach ($resolutions as $field => $resolution) {
            self::recordDiff($match, $game, $field, $resolution, $logValues[$field], $sidecarValues[$field]);
        }

        if ($resolutions['on_play']->chosenSource === 'sidecar' && $resolutions['on_play']->value !== null) {
            foreach ($game->players as $player) {
                $game->players()->updateExistingPivot($player->id, ['on_play' => $player->username === $resolutions['on_play']->value]);
            }
        }

        if ($resolutions['game_boundaries']->chosenSource === 'sidecar') {
            $game->update([
                'started_at' => $view->startedAt,
                'ended_at' => $view->endedAt,
            ]);
        }

        if ($resolutions['game_result']->chosenSource === 'sidecar' && $local !== null) {
            $game->update(['won' => $resolutions['game_result']->value['winner'] === $local->username]);
        }

        if ($resolutions['game_boundaries']->chosenSource === 'sidecar' || $resolutions['game_result']->chosenSource === 'sidecar') {
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: game {$game->mtgo_id} fields taken from sidecar");
        }
    }

    /**
     * `game_boundaries` is the one field whose values are timestamps, so the
     * generic value-equality chooser would call every real game a
     * disagreement. Gate selection stays exactly as for the other fields;
     * only the disagreement verdict is field-aware.
     *
     * @param  array<string, bool>  $flags
     * @param  ?array{started_at: string, ended_at: ?string}  $logBoundaries
     * @param  ?array{started_at: string, ended_at: ?string}  $sidecarBoundaries
     */
    private static function resolveBoundaries(?array $logBoundaries, ?array $sidecarBoundaries, array $flags, SidecarGameView $view, bool $crossOk): Resolution
    {
        $resolution = ResolveFieldAuthority::run(
            'game_boundaries',
            $logBoundaries,
            $sidecarBoundaries,
            $flags['game_boundaries'],
            $view->coverageComplete(),
            $view->allVerified,
            $crossOk,
        );

        if (! $resolution->disagree || ! self::boundariesConflict($logBoundaries, $sidecarBoundaries)) {
            return new Resolution($resolution->value, $resolution->chosenSource, false);
        }

        return $resolution;
    }

    /**
     * True only when both sources have both bounds and at least one of them
     * is further apart than the tolerance.
     *
     * Requiring all four values matters as much as the tolerance does: a
     * game still in progress has no log `ended_at`, so comparing it against
     * the sidecar's completed pair would write a diff row on one tick and
     * delete it on the next, churning `game_field_diffs` for the length of
     * every match.
     *
     * @param  ?array{started_at: string, ended_at: ?string}  $log
     * @param  ?array{started_at: string, ended_at: ?string}  $sidecar
     */
    private static function boundariesConflict(?array $log, ?array $sidecar): bool
    {
        foreach (['started_at', 'ended_at'] as $bound) {
            if (! isset($log[$bound], $sidecar[$bound])) {
                return false;
            }
        }

        foreach (['started_at', 'ended_at'] as $bound) {
            $drift = abs(CarbonImmutable::parse($log[$bound])->diffInSeconds(CarbonImmutable::parse($sidecar[$bound])));

            if ($drift > self::BOUNDARY_TOLERANCE_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<string, Game>  $games  keyed by mtgo id, players loaded
     * @param  array<string, bool>  $flags
     */
    private static function resolveMatch(MtgoMatch $match, Collection $games, SidecarMatchView $view, array $flags, bool $allGamesEligible): void
    {
        $localName = $games->first()?->players->firstWhere('pivot.is_local', true)?->username;

        // When a DB game is missing coverage entirely, a sum over only the
        // games the sidecar saw is not a comparable value (it silently omits
        // that game's contribution), so treat it as no data rather than as a
        // value that happens to disagree with the log.
        $sidecarValue = null;
        if ($view->matchEnded && $allGamesEligible && $localName !== null) {
            $wins = 0;
            $losses = 0;
            foreach ($view->games as $g) {
                if ($g->winnerName === null) {
                    continue;
                }
                $g->winnerName === $localName ? $wins++ : $losses++;
            }
            $sidecarValue = [
                'outcome' => MtgoMatch::determineOutcome($wins, $losses)->value,
                'games_won' => $wins,
                'games_lost' => $losses,
            ];
        }

        $logValue = $match->outcome === null ? null : [
            'outcome' => $match->outcome->value,
            'games_won' => (int) $match->games_won,
            'games_lost' => (int) $match->games_lost,
        ];

        $resolution = ResolveFieldAuthority::run('match_result', $logValue, $sidecarValue, $flags['match_result'], $view->matchEnded && $allGamesEligible, $view->allVerified, $allGamesEligible);

        self::recordDiff($match, null, 'match_result', $resolution, $logValue, $sidecarValue);

        if ($resolution->chosenSource === 'sidecar' && $match->state === MatchState::Complete) {
            $match->update([
                'outcome' => $resolution->value['outcome'],
                'games_won' => $resolution->value['games_won'],
                'games_lost' => $resolution->value['games_lost'],
            ]);
        }
    }

    private static function recordDiff(MtgoMatch $match, ?Game $game, string $field, Resolution $resolution, mixed $logValue, mixed $sidecarValue): void
    {
        $query = GameFieldDiff::query()
            ->where('match_id', $match->id)
            ->where('field', $field)
            ->when($game, fn ($q) => $q->where('game_id', $game->id), fn ($q) => $q->whereNull('game_id'));

        if (! $resolution->disagree) {
            $query->delete();

            return;
        }

        $existing = (clone $query)->first();

        $attributes = [
            'match_id' => $match->id,
            'game_id' => $game?->id,
            'field' => $field,
            'log_value' => is_array($logValue) ? $logValue : ['value' => $logValue],
            'sidecar_value' => is_array($sidecarValue) ? $sidecarValue : ['value' => $sidecarValue],
            'chosen_source' => $resolution->chosenSource,
        ];

        if ($existing) {
            $existing->update($attributes);
        } else {
            GameFieldDiff::create($attributes);
        }
    }
}
