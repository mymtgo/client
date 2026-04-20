<?php

namespace App\Actions\Tournaments;

use App\Enums\TournamentState;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Support\Str;

class LinkMatchToTournament
{
    /**
     * Link a freshly created tournament match to its Tournament record.
     *
     * @param  array<string, string>  $gameMeta  Key-value block from the match join event
     */
    public static function run(MtgoMatch $match, array $gameMeta): void
    {
        $description = $gameMeta['Description'] ?? '';

        if (! preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $description, $matches)) {
            return;
        }

        $eventId = (int) $matches[1];
        $round = (int) $matches[2];

        $tournament = Tournament::firstOrCreate(
            ['event_id' => $eventId],
            [
                'token' => (string) Str::uuid(),
                'type' => TournamentType::fromPlayFormatCd($gameMeta['PlayFormatCd'] ?? null)?->value,
                'format' => trim($gameMeta['GameStructureCd'] ?? '') ?: null,
                'state' => TournamentState::RoundInProgress->value,
                'participated' => true,
            ]
        );

        if (! $tournament->participated) {
            $tournament->update(['participated' => true]);
        }

        $participantLoginIds = null;
        if (isset($gameMeta['PlayerIds'])) {
            $participantLoginIds = array_map('intval', explode(',', $gameMeta['PlayerIds']));
        }

        $match->update([
            'tournament_id' => $tournament->id,
            'tournament_round' => $round,
            'participant_login_ids' => $participantLoginIds,
        ]);
    }
}
