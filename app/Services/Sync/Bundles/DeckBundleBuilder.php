<?php

declare(strict_types=1);

namespace App\Services\Sync\Bundles;

use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use App\Services\Sync\DeckClientId;
use Illuminate\Support\Carbon;

/**
 * Builds the canonical deck bundle and its metadata sidecar.
 *
 * No cards array on a version: the signature IS the cards
 * (DeckVersion::getCardsAttribute derives them), so carrying both would be
 * the same bytes twice with a chance to disagree.
 *
 * Sideboard guides and matchup notes (canonical_version 3) ride along too:
 * both are player-authored content keyed on (deck, archetype), and the deck
 * is the only bundle that owns them. Archetypes travel as uuids, never local
 * ids, like everywhere else in sync.
 *
 * Cover art and the deck-level archetype (canonical_version 4) travel as the
 * card's MTGO id and the archetype uuid.
 */
class DeckBundleBuilder
{
    /**
     * The dot-notation relation paths this builder reads, for a caller
     * (SyncRunner) to eager-load with ->with() ahead of a batch of builds,
     * instead of paying loadMissing's per-row query cost across a whole
     * lazyById scan.
     *
     * @return array<int, string>
     */
    public function relations(): array
    {
        return ['versions', 'cover', 'archetype', 'sideboardGuides.archetype', 'sideboardGuides.cards', 'archetypeNotes.archetype'];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Deck $deck): array
    {
        $deck->loadMissing($this->relations());

        // Deterministic ordering (modified_at, then signature as a
        // content-derived tiebreak, unique per deck) is what makes two
        // devices produce byte-identical bundles for the same deck. The
        // local autoincrement id is never used: it is not content and
        // differs across devices.
        $versions = $deck->versions
            ->sort(fn (DeckVersion $a, DeckVersion $b) => [$a->modified_at?->getTimestamp(), $a->signature]
                <=> [$b->modified_at?->getTimestamp(), $b->signature])
            ->values();

        return [
            'deck' => [
                'mtgo_id' => (string) $deck->mtgo_id,
                'name' => $deck->name,
                'original_name' => $deck->original_name,
                'format' => $deck->format,
                'color_identity' => $deck->color_identity,
                // Cross-device identities only: cards.mtgo_id is the MTGO
                // catalog id and archetypes.uuid is the API's key. Local
                // autoincrement ids differ per device.
                'cover_mtgo_id' => $deck->cover?->mtgo_id === null ? null : (string) $deck->cover->mtgo_id,
                'archetype_uuid' => $deck->archetype?->uuid,
            ],
            'versions' => $versions->map(fn (DeckVersion $version) => [
                'signature' => $version->signature,
                'modified_at' => self::datetime($version->modified_at),
            ])->all(),
            'guides' => $this->guidesPayload($deck),
            'notes' => $this->notesPayload($deck),
        ];
    }

    /**
     * One guide per opponent archetype (unique per deck, so the uuid alone
     * is a stable sort key); cards by direction then oracle_id, unique per
     * guide.
     *
     * @return array<int, array<string, mixed>>
     */
    private function guidesPayload(Deck $deck): array
    {
        return $deck->sideboardGuides
            ->sortBy(fn (SideboardGuide $guide) => $guide->archetype->uuid)
            ->values()
            ->map(fn (SideboardGuide $guide) => [
                'archetype_uuid' => $guide->archetype->uuid,
                'cards' => $guide->cards
                    ->sort(fn (SideboardGuideCard $a, SideboardGuideCard $b) => [$a->direction->value, $a->oracle_id]
                        <=> [$b->direction->value, $b->oracle_id])
                    ->values()
                    ->map(fn (SideboardGuideCard $card) => [
                        'oracle_id' => $card->oracle_id,
                        'direction' => $card->direction->value,
                        'quantity' => (int) $card->quantity,
                    ])->all(),
            ])->all();
    }

    /**
     * Notes have no natural key: several free-text rows per (deck,
     * archetype) are normal. created_at is carried because the overlay
     * lists notes newest-first, and it doubles as the sort key here, with
     * the body as a content-derived tiebreak for same-second rows (never
     * the local row id, which differs across devices).
     *
     * @return array<int, array<string, mixed>>
     */
    private function notesPayload(Deck $deck): array
    {
        return $deck->archetypeNotes
            ->sort(fn (DeckArchetypeNote $a, DeckArchetypeNote $b) => [$a->archetype->uuid, $a->created_at?->getTimestamp(), $a->body]
                <=> [$b->archetype->uuid, $b->created_at?->getTimestamp(), $b->body])
            ->values()
            ->map(fn (DeckArchetypeNote $note) => [
                'archetype_uuid' => $note->archetype->uuid,
                'body' => $note->body,
                'created_at' => self::datetime($note->created_at),
            ])->all();
    }

    public function clientId(Deck $deck): string
    {
        return DeckClientId::for((string) $deck->mtgo_id);
    }

    /**
     * Every sidecar field is derived from bundle content, so a sidecar-only
     * change can never drift from what actually uploads.
     *
     * @return array<string, mixed>
     */
    public function sidecar(Deck $deck): array
    {
        $bundle = $this->build($deck);

        return array_filter([
            'name' => $bundle['deck']['name'],
            'format' => $bundle['deck']['format'],
            'color_identity' => $bundle['deck']['color_identity'],
        ], fn (mixed $value) => $value !== null);
    }

    private static function datetime(?Carbon $value): ?string
    {
        return $value?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
