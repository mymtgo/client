<?php

namespace App\Actions\Limited\Read;

use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Data\Front\DraftNotesData;
use App\Models\DraftPick;

class BuildDraftNotes
{
    /**
     * The live draft's current pick, or null when no draft is live. "Live"
     * is whatever SyncDraftNotesWindowVisibility::liveDraft() says, so the
     * window and its opener never disagree.
     */
    public static function run(): ?DraftNotesData
    {
        $draft = SyncDraftNotesWindowVisibility::liveDraft();

        if ($draft === null) {
            return null;
        }

        /** @var DraftPick|null $pick */
        $pick = $draft->picks()->reorder()->orderByDesc('ordinal')->first();

        $pickedName = null;
        if ($pick?->picked_catalog_id) {
            $card = ResolveCatalogCards::run([$pick->picked_catalog_id])->get((string) $pick->picked_catalog_id);
            $pickedName = $card?->name ?? '#'.$pick->picked_catalog_id;
        }

        return new DraftNotesData(
            draftId: $draft->id,
            leagueId: $draft->league_id,
            state: $draft->state->value,
            ordinal: $pick?->ordinal,
            label: $pick ? "P{$pick->pack_number}p{$pick->pick_number}" : null,
            cardsInPack: $pick ? count($pick->cards_available ?? []) : null,
            deadlineAt: $pick?->deadline_at?->toIso8601String(),
            pickedCatalogId: $pick?->picked_catalog_id,
            pickedName: $pickedName,
            note: $pick?->note,
        );
    }
}
