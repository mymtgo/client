<?php

namespace App\Actions\Overlay;

use App\Enums\DraftState;
use App\Facades\AppSettings;
use App\Models\Draft;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDraftNotesWindowVisibility
{
    /**
     * Seconds the window stays up after a draft finishes, so the last note
     * can be finished before the window disappears.
     */
    public const GRACE_SECONDS = 30;

    /**
     * The desired state last actually pushed to the window API, or null when
     * nothing has been pushed yet in this process.
     *
     * Every call to the Electron window API is a blocking HTTP round trip to
     * localhost:4000. Reconciling from the pipeline tick means that fires
     * every second forever, even on a machine that never drafts, so the
     * reconcile only talks to Electron when the desired state actually
     * flips. Same reasoning as AdvanceMatchState.php:190-193, which syncs the
     * game overlay only when the match state moved.
     */
    private static ?bool $lastApplied = null;

    /**
     * Reconcile the draft notes window against draft state: open while a
     * draft is live (and the setting is on), closed otherwise. Idempotent,
     * one indexed query, safe to call on every pipeline tick. Abandoned
     * drafts close immediately; finished drafts keep the window for
     * GRACE_SECONDS.
     *
     * @param  bool  $force  Push the desired state to the window API even if
     *                       it matches the last one pushed. Used at boot (the
     *                       memo says nothing about windows this process did
     *                       not open) and from the settings toggle (the user
     *                       flipped the switch and expects the window to react).
     */
    public static function run(bool $force = false): void
    {
        try {
            // Draft query first, settings second: with no draft live there is
            // nothing to show whatever the setting says, and skipping the
            // settings-file read is the point on an idle machine.
            $shouldShow = self::liveDraft() !== null && AppSettings::showDraftNotesWindow();

            if (! $force && $shouldShow === self::$lastApplied) {
                return;
            }

            if ($shouldShow) {
                OpenDraftNotesWindow::run();
            } else {
                CloseDraftNotesWindow::run();
            }

            self::$lastApplied = $shouldShow;
        } catch (Throwable $e) {
            // The window is cosmetic; a wedged or absent Electron API must
            // never abort a pipeline tick. The memo is deliberately left
            // untouched so the next tick retries rather than believing this
            // state was applied.
            Log::warning('Draft notes window sync failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Forget the last applied state so the next run() talks to the window API
     * again. For tests, which share one process across many scenarios.
     */
    public static function reset(): void
    {
        self::$lastApplied = null;
    }

    /**
     * The draft the window is about: a Connecting or Picking draft first,
     * otherwise the most recently finished draft still inside its grace
     * window, otherwise null. Shared with the window's own controller so the
     * two never disagree about which draft is "live".
     */
    public static function liveDraft(): ?Draft
    {
        $live = Draft::query()
            ->whereIn('state', [DraftState::Connecting, DraftState::Picking])
            ->latest('id')
            ->first();

        if ($live) {
            return $live;
        }

        return Draft::query()
            ->where('state', DraftState::Finished)
            ->where('ended_at', '>=', now()->subSeconds(self::GRACE_SECONDS))
            ->latest('ended_at')
            ->first();
    }
}
