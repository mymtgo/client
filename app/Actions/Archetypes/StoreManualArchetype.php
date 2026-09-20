<?php

namespace App\Actions\Archetypes;

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
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

        $deck = $resolvedCards !== []
            ? AddArchetypeVariant::run($archetype, $resolvedCards)
            : null;

        if ($sourceMatchId !== null) {
            self::linkOpponentToArchetype($sourceMatchId, $archetype->id, $deck);
        }

        return $archetype->load('decks.cards');
    }

    private static function linkOpponentToArchetype(int $matchId, int $archetypeId, ?ArchetypeDeck $deck): void
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
                    'archetype_deck_id' => $deck?->id,
                ],
            );
        }
    }
}
