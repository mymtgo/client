<?php

namespace App\Actions\Limited\Read;

use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Data\Front\DraftNotePickData;
use App\Data\Front\DraftNotesData;
use App\Models\Card;
use App\Models\DraftPick;
use Illuminate\Support\Collection;

class BuildDraftNotes
{
    /**
     * The live draft's picks, newest one flagged as current, or null when no
     * draft is live. "Live" is whatever SyncDraftNotesWindowVisibility::liveDraft()
     * says, so the window and its opener never disagree.
     *
     * Every pick ships, not just the current one: the window lets the player
     * step back to an earlier pick and write the note retroactively, and a
     * round trip per step would fight the one-second poll for the same prop.
     */
    public static function run(): ?DraftNotesData
    {
        $draft = SyncDraftNotesWindowVisibility::liveDraft();

        if ($draft === null) {
            return null;
        }

        /** @var Collection<int, DraftPick> $picks */
        $picks = $draft->picks()->reorder()->orderBy('ordinal')->get();

        // One lookup for the whole draft. Resolving per pick would be forty-odd
        // queries a tick by the end of pack three.
        $cards = ResolveCatalogCards::run($picks->pluck('picked_catalog_id')->filter());

        $last = $picks->last();

        return new DraftNotesData(
            draftId: $draft->id,
            leagueId: $draft->league_id,
            state: $draft->state->value,
            currentOrdinal: $last ? (int) $last->ordinal : null,
            picks: $picks->map(fn (DraftPick $pick) => self::describe($pick, $cards))->all(),
        );
    }

    /**
     * @param  Collection<string, Card>  $cards  keyed by string catalog id
     */
    private static function describe(DraftPick $pick, Collection $cards): DraftNotePickData
    {
        $pickedName = null;
        if ($pick->picked_catalog_id) {
            $pickedName = $cards->get((string) $pick->picked_catalog_id)?->name ?? '#'.$pick->picked_catalog_id;
        }

        return new DraftNotePickData(
            ordinal: (int) $pick->ordinal,
            label: "P{$pick->pack_number}p{$pick->pick_number}",
            cardsInPack: count($pick->cards_available ?? []),
            deadlineAt: $pick->deadline_at?->toIso8601String(),
            pickedCatalogId: $pick->picked_catalog_id,
            pickedName: $pickedName,
            note: $pick->note,
        );
    }
}
