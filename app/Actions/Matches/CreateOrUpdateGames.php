<?php

namespace App\Actions\Matches;

use App\Actions\Util\ExtractJson;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;

class CreateOrUpdateGames
{
    /**
     * Create or update game records for all games in the match's events.
     */
    public static function run(MtgoMatch $match, Collection $events): void
    {
        $games = $events->groupBy('game_id')->filter(
            fn ($group, $key) => $key !== '' && $key !== null
        );

        $gameIds = $games->keys();

        $decksEvents = LogEvent::where('event_type', LogEventType::DECK_USED->value)
            ->whereIn('game_id', $gameIds)
            ->get();

        $gameIndex = 0;

        foreach ($games as $gameId => $gameEvents) {
            $playerDeck = $decksEvents->first(
                fn ($event) => (int) $event->game_id === (int) $gameId
            );

            $deckJson = $playerDeck
                ? (ExtractJson::run($playerDeck->raw_text)->first() ?: [])
                : [];

            CreateGames::run($match, $gameId, $gameEvents, $gameIndex, $deckJson);
            $gameIndex++;
        }
    }
}
