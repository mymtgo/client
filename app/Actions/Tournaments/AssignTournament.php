<?php

namespace App\Actions\Tournaments;

use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class AssignTournament
{
    /**
     * Upsert a tournament for a tournament-bearing match and link them.
     * Idempotent: matches already linked are skipped.
     *
     * Tournaments are keyed by mtgo_event_id alone — one tournament row per
     * event, regardless of how many decks the local player ran in it.
     */
    public static function run(MtgoMatch $match): void
    {
        if ($match->tournament_id !== null) {
            return;
        }

        if ($match->tournament_event_id === null) {
            return;
        }

        $tournament = Tournament::query()
            ->where('mtgo_event_id', $match->tournament_event_id)
            ->first()
            ?? self::create($match);

        $match->update(['tournament_id' => $tournament->id]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to tournament #{$tournament->id}", [
            'mtgo_event_id' => $tournament->mtgo_event_id,
            'name' => $tournament->name,
        ]);
    }

    private static function create(MtgoMatch $match): Tournament
    {
        $meta = FetchTournamentMetadata::run($match->tournament_event_id);

        $synthesized = $meta === null;
        $name = $synthesized
            ? self::synthesizeName($match)
            : $meta['name'];

        $attributes = [
            'mtgo_event_id' => $match->tournament_event_id,
            'token' => $match->tournament_token,
            'name' => $name,
            'format' => $meta['format'] ?? null,
            'started_at' => $meta['started_at'] ?? ($match->started_at ?? now()),
            'name_synthesized' => $synthesized,
        ];

        try {
            return Tournament::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return Tournament::query()
                ->where('mtgo_event_id', $match->tournament_event_id)
                ->firstOrFail();
        }
    }

    private static function synthesizeName(MtgoMatch $match): string
    {
        $when = ($match->started_at ?? now())->toLocal()->format('d-m-Y');

        return "Tournament {$match->tournament_event_id} ({$when})";
    }
}
