<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\CardGameStat;
use App\Models\Game;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class ApplyCardCast implements SubHandler
{
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
    {
        if (! $event->game_id || empty($parsed['event']['card']) || empty($parsed['event']['player'])) {
            return;
        }

        $localUsername = $context->localUsername();
        if ($localUsername === null) {
            return;
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();
        if (! $game) {
            return;
        }

        $card = $parsed['event']['card'];
        $oracleId = $context->oracleByMultiverseId((int) $card['multiverse_id']);
        if ($oracleId === null) {
            return;
        }

        $deckVersionId = $game->match?->deck_version_id;
        if ($deckVersionId === null) {
            return;  // FK requires a deck version; skip until match is linked
        }

        $opponent = $parsed['event']['player'] !== $localUsername;
        $turn = (int) ($game->turn_count ?? 0);

        $row = CardGameStat::firstOrCreate(
            [
                'oracle_id' => $oracleId,
                'game_id' => $game->id,
                'opponent' => $opponent,
                'turn_number' => $turn,
            ],
            [
                'deck_version_id' => $deckVersionId,
                'quantity' => 0,
                'won' => $game->won ?? false,
                'cast' => 0,
            ],
        );

        $row->increment('cast');
    }
}
