<?php

namespace App\Actions\Drafts;

use App\Enums\DraftState;
use App\Models\Draft;
use App\Models\DraftPick;
use Carbon\Carbon;

class ReconcileDraftFromEnded
{
    /**
     * FlsBoosterDraftEndedMessage.Picks is the authoritative ordered list of
     * the local player's picks. Picks[i] is ordinal i + 1. Fill anything the
     * live stream missed; never touch notes; never shrink cards_available.
     *
     * @param  array<int, array{PackID?: int, Selections?: array<int, array{CardID?: int, CatalogID?: int}>, Time?: string}>  $picks
     */
    public static function run(Draft $draft, array $picks, Carbon $endedAt): void
    {
        $packSize = max(1, $draft->pack_size);

        foreach (array_values($picks) as $index => $pick) {
            $ordinal = $index + 1;
            $catalogId = (int) ($pick['Selections'][0]['CatalogID'] ?? 0);
            $cardId = (int) ($pick['Selections'][0]['CardID'] ?? 0);

            $row = DraftPick::firstOrNew(['draft_id' => $draft->id, 'ordinal' => $ordinal]);

            if (! $row->exists) {
                $row->fill([
                    'pack_number' => intdiv($ordinal - 1, $packSize) + 1,
                    'pick_number' => (($ordinal - 1) % $packSize) + 1,
                    'cards_available' => [$catalogId],
                    'reservations' => [],
                ]);
            }

            $row->fill([
                'pack_id' => $row->pack_id ?? ($pick['PackID'] ?? null),
                'picked_catalog_id' => $catalogId ?: $row->picked_catalog_id,
                'picked_card_id' => $cardId ?: $row->picked_card_id,
                'picked_at' => $row->picked_at ?? (isset($pick['Time']) ? Carbon::parse($pick['Time'])->utc() : null),
            ])->save();
        }

        $draft->update([
            'state' => DraftState::Finished,
            'ended_at' => $draft->ended_at ?? $endedAt,
            'picks_expected' => max($draft->picks_expected, count($picks)),
        ]);
    }
}
