<?php

namespace App\Actions\Archetypes;

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Support\Str;

class StoreManualArchetype
{
    /**
     * @param  array<int, array{mtgo_id: int, oracle_id: string|null, quantity: int, sideboard: bool}>  $resolvedCards
     */
    public static function run(
        string $name,
        string $format,
        ?string $colorIdentity,
        array $resolvedCards,
        ?int $sourceMatchId = null,
        bool $incomplete = false,
    ): Archetype {
        $deviceId = AppSettings::deviceId() ?? '00000000';
        $prefix = substr($deviceId, 0, 8);
        $uuid = $prefix.'-'.Str::uuid();

        $archetype = Archetype::create([
            'uuid' => $uuid,
            'name' => $name,
            'format' => strtolower($format),
            'color_identity' => $colorIdentity,
            'manual' => true,
            'source_match_id' => $sourceMatchId,
            'incomplete' => $incomplete,
            'decklist_downloaded_at' => now(),
        ]);

        $pivotData = [];

        foreach ($resolvedCards as $cardData) {
            if (empty($cardData['oracle_id'])) {
                continue;
            }

            $card = Card::where('oracle_id', $cardData['oracle_id'])->first();

            if (! $card) {
                continue;
            }

            $pivotData[$card->id] = [
                'quantity' => $cardData['quantity'],
                'sideboard' => $cardData['sideboard'],
            ];
        }

        $archetype->cards()->sync($pivotData);

        if ($sourceMatchId !== null) {
            self::linkOpponentToArchetype($sourceMatchId, $archetype->id);
        }

        return $archetype->load('cards');
    }

    private static function linkOpponentToArchetype(int $matchId, int $archetypeId): void
    {
        $match = MtgoMatch::with('games.players')->find($matchId);

        if (! $match) {
            return;
        }

        $opponentIds = collect();

        foreach ($match->games as $game) {
            foreach ($game->players as $player) {
                if (! $player->pivot->is_local) {
                    $opponentIds->push($player->id);
                }
            }
        }

        foreach ($opponentIds->unique() as $opponentId) {
            MatchArchetype::updateOrCreate(
                [
                    'mtgo_match_id' => $matchId,
                    'player_id' => $opponentId,
                ],
                [
                    'archetype_id' => $archetypeId,
                ],
            );
        }
    }
}
