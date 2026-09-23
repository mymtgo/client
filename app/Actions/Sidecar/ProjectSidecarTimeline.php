<?php

namespace App\Actions\Sidecar;

use App\Actions\Cards\CreateMissingCards;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameTimeline;
use App\Sidecar\SidecarGameView;
use App\Sidecar\TimelineFold;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectSidecarTimeline
{
    public const SOURCE = 'sidecar';

    /**
     * Folds the game's sidecar events into game_timelines frames, replacing
     * whatever frames were there (log or sidecar) and marking the game as
     * sidecar-owned. One frame per SDK tick (events sharing a ts) that
     * changed visible state; clock-only ticks write nothing. Idempotent.
     * A stream with neither game_started nor a keyframe has no anchor and
     * writes nothing, leaving ownership untouched, as does a view with no
     * player names: without names there are no players to anchor a frame to.
     *
     * This runs on every projection pass, which during a live game is every
     * pipeline tick. The fold is always recomputed: it is a cheap in-memory
     * pass over rows the projection has already read. The write is what costs,
     * so it is skipped when nothing new has landed since the stored frames,
     * leaving a tick that added no events as pure reads.
     */
    public static function run(Game $game, SidecarGameView $view): void
    {
        if ($view->playerNames === []) {
            return;
        }

        $events = GameEvent::query()
            ->where('game_mtgo_id', (string) $game->mtgo_id)
            ->orderBy('session_started_at')
            ->orderBy('seq')
            ->get(['type', 'data', 'ts', 'session_started_at', 'seq']);

        $tz = AppSettings::systemTimezone() ?: 'UTC';
        $fold = TimelineFold::start($view->playerNames);
        $anchored = false;
        $now = now();
        $rows = [];
        $catalogIds = [];

        // Events arrive ordered by (session_started_at, seq) and a tick is the run of
        // events sharing one ts, so this assumes ts is non-decreasing within a game.
        foreach ($events->groupBy(fn (GameEvent $e) => $e->ts->format('Y-m-d\TH:i:s.v')) as $tick) {
            $changed = false;

            foreach ($tick as $event) {
                if ($event->type === 'game_started' || $event->type === 'keyframe') {
                    $anchored = true;
                }
                $changed = $fold->apply($event->type, $event->data ?? []) || $changed;
            }

            if (! $anchored || ! $changed) {
                continue;
            }

            $frame = $fold->frame();
            foreach ($frame['Cards'] as $card) {
                if ($card['CatalogID'] > 0) {
                    $catalogIds[$card['CatalogID']] = true;
                }
            }

            $rows[] = [
                'game_id' => $game->id,
                'timestamp' => $tick->first()->ts->setTimezone($tz)->format('H:i:s.v'),
                'content' => json_encode($frame),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === [] || self::alreadyStored($game, $rows)) {
            return;
        }

        CreateMissingCards::run(array_keys($catalogIds));

        // Non-critical: if the DB is locked by concurrent ingestion, skip and let
        // the next pass rewrite. One game's frames must not abort the per-game
        // loop in ApplySidecarProjection and take the other games down with it.
        try {
            self::write($game, $rows);
        } catch (QueryException $e) {
            Log::channel('pipeline')->info("ProjectSidecarTimeline: timeline write skipped for game {$game->id}: {$e->getMessage()}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function write(Game $game, array $rows): void
    {
        DB::transaction(function () use ($game, $rows) {
            GameTimeline::where('game_id', $game->id)->delete();
            // Five columns per row, so the chunk stays under the 999 bind
            // variables a legacy SQLite build allows in one statement.
            foreach (array_chunk($rows, 180) as $chunk) {
                GameTimeline::insert($chunk);
            }
            $game->update(['timeline_source' => self::SOURCE]);
            // The update is a no-op once the game is already sidecar-owned, and
            // the frames go in through the query builder, so nothing else here
            // moves games.updated_at. Sync reads that (and, via $touches, the
            // match's) to decide what to ship, so a rewrite that did not touch
            // would never leave this machine.
            $game->touch();
        });
    }

    /**
     * Whether the stored frames already are these frames, so the rewrite can be
     * skipped. Frames only ever grow at the tail within a game, so the stored
     * count plus the last frame's timestamp identify the fold's output without
     * reading 180 JSON blobs back. The count runs first and settles it on its
     * own in the common case, so the other two queries are the exception.
     * A game the sidecar does not own is never a
     * match: log frames must be overwritten even when the counts happen to
     * line up.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function alreadyStored(Game $game, array $rows): bool
    {
        if ($game->timeline_source !== self::SOURCE) {
            return false;
        }

        if (GameTimeline::query()->where('game_id', $game->id)->count() !== count($rows)) {
            return false;
        }

        $lastId = GameTimeline::query()->where('game_id', $game->id)->max('id');
        $storedTimestamp = GameTimeline::query()->whereKey($lastId)->value('timestamp');

        return $storedTimestamp === $rows[array_key_last($rows)]['timestamp'];
    }
}
