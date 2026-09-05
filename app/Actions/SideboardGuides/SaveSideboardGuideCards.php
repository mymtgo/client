<?php

namespace App\Actions\SideboardGuides;

use App\Models\SideboardGuide;
use Illuminate\Support\Facades\DB;

class SaveSideboardGuideCards
{
    /**
     * Replace the guide's planned cards with the submitted set.
     *
     * Delete-then-insert inside one transaction: the editor always submits the
     * whole plan, and a partial write would leave a guide that matches neither
     * what the player saw nor what they saved.
     *
     * @param  array<int, array{oracle_id: string, direction: string, quantity: int}>  $cards
     */
    public static function run(SideboardGuide $guide, array $cards): void
    {
        DB::transaction(function () use ($guide, $cards): void {
            $guide->cards()->delete();

            if ($cards !== []) {
                $guide->cards()->createMany(array_map(fn (array $card) => [
                    'oracle_id' => $card['oracle_id'],
                    'direction' => $card['direction'],
                    'quantity' => (int) $card['quantity'],
                ], $cards));
            }

            $guide->touch();
        });
    }
}
