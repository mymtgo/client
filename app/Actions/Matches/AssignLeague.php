<?php

namespace App\Actions\Matches;

use App\Enums\LeagueState;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Desktop\Facades\Settings;

class AssignLeague
{
    /**
     * Assign a league to the match — real league if token present, phantom otherwise.
     */
    public static function run(MtgoMatch $match, array $gameMeta): void
    {
        if (! empty($gameMeta['League Token'])) {
            $leagueKey = [
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
            ];

            // Include deck version in the composite key when available,
            // so re-entering the same league with a different deck creates a new run.
            if ($match->deck_version_id) {
                $leagueKey['deck_version_id'] = $match->deck_version_id;
            }

            // Find the most recent league matching these keys that is still active
            // (not complete). If the user re-enters a league with the same deck
            // after completing a 5-match run, we create a new league row.
            $league = League::where($leagueKey)
                ->where('state', '!=', LeagueState::Complete)
                ->latest('started_at')
                ->first();

            $isNew = false;
            if (! $league) {
                $league = League::create(array_merge($leagueKey, [
                    'started_at' => now(),
                    'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
                ]));
                $isNew = true;
            }

            if ($isNew) {
                // Mark older active leagues with the same token as partial
                League::where('token', $gameMeta['League Token'])
                    ->where('format', $gameMeta['PlayFormatCd'])
                    ->where('state', LeagueState::Active)
                    ->where('id', '!=', $league->id)
                    ->where('started_at', '<=', $league->started_at)
                    ->update(['state' => LeagueState::Partial]);
            }
        } elseif (! Settings::get('hide_phantom_leagues')) {
            $match->refresh();

            $deckId = $match->deck_version_id
                ? DeckVersion::find($match->deck_version_id)?->deck_id
                : null;

            $league = self::findOrCreatePhantomLeague($gameMeta, $deckId, $match->deck_version_id);
        } else {
            return;
        }

        $match->update(['league_id' => $league->id]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
            'league_name' => $league->name,
            'phantom' => $league->phantom,
            'has_league_token' => ! empty($gameMeta['League Token']),
        ]);
    }

    /**
     * Find an existing phantom league for the given deck and format, or create a new one.
     *
     * We only append to a phantom league when:
     *  - It belongs to the same deck (prevents cross-deck contamination)
     *  - It is not already flagged as having a deck change
     *  - It has fewer than 5 matches (league run limit)
     *
     * If the deck is unknown (DetermineMatchDeck found no signature match) we always
     * create a fresh league rather than risk polluting an existing one.
     */
    private static function findOrCreatePhantomLeague(array $gameMeta, ?int $deckId, ?int $deckVersionId = null): League
    {
        if ($deckId) {
            $existing = League::where('format', $gameMeta['PlayFormatCd'])
                ->where('phantom', true)
                ->where('deck_change_detected', false)
                ->has('matches', '<', 5)
                ->whereHas('matches', fn ($q) => $q
                    ->join('deck_versions as dv', 'dv.id', '=', 'matches.deck_version_id')
                    ->where('dv.deck_id', $deckId)
                )
                ->latest('started_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return League::create([
            'token' => Str::random(),
            'format' => $gameMeta['PlayFormatCd'],
            'phantom' => true,
            'deck_version_id' => $deckVersionId,
            'started_at' => now(),
            'name' => 'Phantom '.trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
        ]);
    }
}
