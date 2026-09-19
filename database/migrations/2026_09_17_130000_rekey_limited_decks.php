<?php

use App\Models\Deck;
use App\Models\League;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Limited decks used to be keyed "limited:league-{local id}", which is
     * device-specific and can collide across machines. Re-key the ones whose
     * league now has an MTGO identity; leave the rest, which stay local-only
     * and out of sync (spec 2026-09-17).
     *
     * Idempotent: a deck already on the new key does not match the prefix.
     */
    public function up(): void
    {
        Deck::withTrashed()
            ->where('mtgo_id', 'like', Deck::LOCAL_LIMITED_PREFIX.'%')
            ->cursor()
            ->each(function (Deck $deck): void {
                $localId = (int) substr((string) $deck->mtgo_id, strlen(Deck::LOCAL_LIMITED_PREFIX));
                $league = League::withTrashed()->find($localId);

                if ($league?->event_id === null || $league?->mtgo_course_id === null) {
                    return;
                }

                $key = "limited:event-{$league->event_id}-{$league->mtgo_course_id}";

                if (Deck::withTrashed()->where('mtgo_id', $key)->exists()) {
                    return;
                }

                $deck->forceFill(['mtgo_id' => $key])->saveQuietly();
            });
    }

    public function down(): void
    {
        // Irreversible: the local ids the old keys encoded are not recoverable
        // from the new ones, and the old shape was the bug.
    }
};
