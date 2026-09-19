<?php

declare(strict_types=1);

namespace App\Services\Sync\Bundles;

use App\Models\Card;
use App\Models\Deck;
use App\Services\Sync\LocalArchetypeId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Restores a deck and its versions from a canonical bundle.
 *
 * Runs inside Model::withoutEvents() and one transaction, same as the match
 * importer. Unlike a match's children, deck versions are not delete-then-
 * reinserted: `deck_versions` has no natural key of its own besides
 * `(deck_id, signature)` (see DeckBundleBuilder), and matches point at a
 * specific `deck_version_id`. Recreating every version on each sync would
 * hand existing versions fresh autoincrement ids and orphan any local
 * match that already points at the old one. Only signatures this device
 * has never seen are inserted; a version already present, by signature,
 * keeps its local id untouched.
 *
 * Sideboard guides and matchup notes, by contrast, ARE whole-resource
 * snapshots (delete-then-reinsert by deck): nothing else points at their
 * ids, and a guide's cards are always saved as a complete plan locally too
 * (see SaveSideboardGuideCards).
 */
class DeckBundleImporter
{
    public function import(array $bundle, string $hash): void
    {
        $deck = null;

        Model::withoutEvents(function () use ($bundle, &$deck): void {
            DB::transaction(function () use ($bundle, &$deck): void {
                $deck = $this->upsertDeckRow($bundle['deck']);
                $this->insertNewVersions($deck, $bundle['versions']);

                // A bundle built before canonical_version 3 carries no guide
                // or note keys. Absence means "unknown", not "none": wiping
                // local guides because the other device had not upgraded
                // yet would lose real work, so both sections are left alone
                // until a bundle that actually speaks for them arrives.
                if (array_key_exists('guides', $bundle) && array_key_exists('notes', $bundle)) {
                    $this->replaceGuidesAndNotes($deck, $bundle['guides'], $bundle['notes']);
                }
            });
        });

        $deck->forceFill(['synced_hash' => $hash, 'synced_at' => now()])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertDeckRow(array $data): Deck
    {
        $deck = Deck::withTrashed()->firstOrNew(['mtgo_id' => $data['mtgo_id']]);

        $values = [
            'name' => $data['name'],
            'original_name' => $data['original_name'],
            'format' => $data['format'],
            'color_identity' => $data['color_identity'],
        ];

        // Bundles from before canonical_version 4 carry neither key; absence
        // is "unknown", so the local values are left alone, matching how
        // guides and notes are handled below.
        if (array_key_exists('cover_mtgo_id', $data)) {
            $values['cover_id'] = $data['cover_mtgo_id'] === null
                ? null
                : Card::query()->where('mtgo_id', $data['cover_mtgo_id'])->whereNotNull('art_crop')->value('id');
        }

        if (array_key_exists('archetype_uuid', $data)) {
            $values['archetype_id'] = $data['archetype_uuid'] === null ? null : LocalArchetypeId::for($data['archetype_uuid']);
        }

        $deck->forceFill($values)->save();

        return $deck;
    }

    /**
     * @param  array<int, array<string, mixed>>  $versions
     */
    private function insertNewVersions(Deck $deck, array $versions): void
    {
        $existingSignatures = DB::table('deck_versions')->where('deck_id', $deck->id)->pluck('signature')->all();

        $now = now();

        $rows = collect($versions)
            ->reject(fn (array $version) => in_array($version['signature'], $existingSignatures, true))
            ->map(fn (array $version) => [
                'deck_id' => $deck->id,
                'signature' => $version['signature'],
                'modified_at' => $version['modified_at'] === null ? null : Carbon::parse($version['modified_at']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('deck_versions')->insert($chunk);
        }
    }

    /**
     * @param  array<int, array{archetype_uuid: string, cards: array<int, array{oracle_id: string, direction: string, quantity: int}>}>  $guides
     * @param  array<int, array{archetype_uuid: string, body: string, created_at: string|null}>  $notes
     */
    private function replaceGuidesAndNotes(Deck $deck, array $guides, array $notes): void
    {
        $oldGuideIds = DB::table('sideboard_guides')->where('deck_id', $deck->id)->pluck('id');

        DB::table('sideboard_guide_cards')->whereIn('sideboard_guide_id', $oldGuideIds)->delete();
        DB::table('sideboard_guides')->where('deck_id', $deck->id)->delete();
        DB::table('deck_archetype_notes')->where('deck_id', $deck->id)->delete();

        $now = now();

        foreach ($guides as $guide) {
            $guideId = DB::table('sideboard_guides')->insertGetId([
                'deck_id' => $deck->id,
                'archetype_id' => LocalArchetypeId::for($guide['archetype_uuid']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $cards = collect($guide['cards'])->map(fn (array $card) => [
                'sideboard_guide_id' => $guideId,
                'oracle_id' => $card['oracle_id'],
                'direction' => $card['direction'],
                'quantity' => $card['quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($cards, 500) as $chunk) {
                DB::table('sideboard_guide_cards')->insert($chunk);
            }
        }

        $noteRows = collect($notes)->map(fn (array $note) => [
            'deck_id' => $deck->id,
            'archetype_id' => LocalArchetypeId::for($note['archetype_uuid']),
            'body' => $note['body'],
            // The authored time is content here (notes list newest-first),
            // so it is restored rather than stamped with the import time.
            'created_at' => $note['created_at'] === null ? $now : Carbon::parse($note['created_at']),
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($noteRows, 500) as $chunk) {
            DB::table('deck_archetype_notes')->insert($chunk);
        }
    }
}
