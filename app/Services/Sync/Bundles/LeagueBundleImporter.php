<?php

declare(strict_types=1);

namespace App\Services\Sync\Bundles;

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Restores a league row from a canonical bundle.
 *
 * The league row plus its limited-play children (drafts, draft picks and
 * limited deck snapshots), replaced wholesale like match children: no
 * field-level merge. Runs inside Model::withoutEvents() and one
 * transaction, matching the match and deck importers, so a future observer
 * on League cannot silently regress this into firing enrichment side
 * effects on import.
 */
class LeagueBundleImporter
{
    public function import(array $bundle, string $hash): void
    {
        $league = null;

        Model::withoutEvents(function () use ($bundle, &$league): void {
            DB::transaction(function () use ($bundle, &$league): void {
                $league = $this->upsertLeagueRow($bundle['league']);
                $this->replaceChildren($league, $bundle);
            });
        });

        $league->forceFill(['synced_hash' => $hash, 'synced_at' => now()])->saveQuietly();
    }

    /**
     * Natural key is (token, started_at): leagues.token is a log session
     * token reused across runs, not a league identifier, which is exactly
     * why LeagueClientId pairs it with started_at (see that class).
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertLeagueRow(array $data): League
    {
        $startedAt = $data['started_at'] === null ? null : Carbon::parse($data['started_at']);

        $league = League::withTrashed()->firstOrNew([
            'token' => $data['token'],
            'started_at' => $startedAt,
        ]);

        $league->forceFill([
            'name' => $data['name'],
            'format' => $data['format'],
            'started_at' => $startedAt,
            'joined_at' => $data['joined_at'],
            'dropped_at' => $data['dropped_at'],
            'completed_at' => $data['completed_at'],
            'state' => $data['state'],
            'notes' => $data['notes'],
            'manual' => $data['manual'],
            'deck_change_detected' => $data['deck_change_detected'],
            'kind' => $data['kind'],
            'set_code' => $data['set_code'],
            'mtgo_course_id' => $data['mtgo_course_id'],
            'deck_version_id' => $this->resolveDeckVersionId($data['deck_mtgo_id'], $data['deck_version_signature']),
        ])->save();

        return $league;
    }

    /**
     * Bundles written before the 2026-09-03 amendment carry no child keys;
     * treating them as empty keeps old blobs importable during the full
     * reconcile that the canonical_version bump forces.
     *
     * @param  array<string, mixed>  $bundle
     */
    private function replaceChildren(League $league, array $bundle): void
    {
        $draftIds = DB::table('drafts')->where('league_id', $league->id)->pluck('id');

        DB::table('draft_picks')->whereIn('draft_id', $draftIds)->delete();
        DB::table('drafts')->where('league_id', $league->id)->delete();
        DB::table('limited_deck_snapshots')->where('league_id', $league->id)->delete();

        $now = now();

        foreach ($bundle['drafts'] ?? [] as $draftData) {
            $picks = $draftData['picks'];
            unset($draftData['picks']);

            $draftId = DB::table('drafts')->insertGetId([
                ...$draftData,
                'league_id' => $league->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = array_map(fn (array $pick): array => [
                ...$pick,
                'cards_available' => json_encode($pick['cards_available']),
                'reservations' => json_encode($pick['reservations']),
                'draft_id' => $draftId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $picks);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('draft_picks')->insert($chunk);
            }
        }

        $snapshots = array_map(fn (array $snapshot): array => [
            ...$snapshot,
            'cards' => json_encode($snapshot['cards']),
            'league_id' => $league->id,
            // Resolved when the match is already local; otherwise the match
            // importer re-links by match_token when it lands.
            'match_id' => $snapshot['match_token'] === null
                ? null
                : DB::table('matches')->where('token', $snapshot['match_token'])->value('id'),
            'created_at' => $now,
            'updated_at' => $now,
        ], $bundle['snapshots'] ?? []);

        foreach (array_chunk($snapshots, 500) as $chunk) {
            DB::table('limited_deck_snapshots')->insert($chunk);
        }
    }

    /**
     * Same resolution as MatchBundleImporter::resolveDeckVersionId: deck by
     * mtgo_id, version by (deck_id, signature), null rather than invented
     * when the deck itself has not synced to this device.
     */
    private function resolveDeckVersionId(?string $deckMtgoId, ?string $signature): ?int
    {
        if ($deckMtgoId === null || $signature === null) {
            return null;
        }

        $deck = Deck::withTrashed()->where('mtgo_id', $deckMtgoId)->first();

        if ($deck === null) {
            return null;
        }

        return DeckVersion::firstOrCreate(
            ['deck_id' => $deck->id, 'signature' => $signature],
            ['modified_at' => now()],
        )->id;
    }
}
