<?php

namespace App\Actions\Limited;

use App\Actions\Util\RepairJson;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class ReadRegisteredDeck
{
    /**
     * The decklist MTGO handed back when the match opened
     * (FlsMatchDeckGetRespMessage), or null when it has not sent one (yet).
     *
     * Single reader for every caller that needs the registered deck: the
     * limited snapshot recorder and AssignLeague's pool guard both read the
     * same newest event, repair the same JSON, and map the same card shape.
     *
     * @return list<array{catalog_id: int, quantity: int, sideboard: bool}>|null
     */
    public static function run(MtgoMatch $match): ?array
    {
        $event = self::sourceEvent($match);

        return $event ? self::fromEvent($event) : null;
    }

    /**
     * Newest registered-deck log event for the match. Exposed so callers that
     * also need the capture time (the snapshot recorder) do not have to run
     * the same query twice.
     */
    public static function sourceEvent(MtgoMatch $match): ?LogEvent
    {
        return LogEvent::query()
            ->where('event_type', LogEventType::MATCH_DECK_REGISTERED->value)
            ->where('match_token', $match->token)
            ->orderByDesc('logged_at')
            ->first();
    }

    /**
     * @return list<array{catalog_id: int, quantity: int, sideboard: bool}>|null
     */
    public static function fromEvent(LogEvent $event): ?array
    {
        $json = RepairJson::firstObject($event->raw_text);

        if (! $json || empty($json['Cards'])) {
            return null;
        }

        return array_values(array_map(fn (array $card): array => [
            'catalog_id' => (int) $card['CatalogID'],
            'quantity' => (int) $card['Quantity'],
            'sideboard' => (bool) ($card['InSideboard'] ?? false),
        ], $json['Cards']));
    }

    /**
     * Main-deck catalog id => quantity, summed over duplicate rows.
     *
     * @param  list<array{catalog_id: int, quantity: int, sideboard: bool}>  $cards
     * @return array<int, int>
     */
    public static function mainDeck(array $cards): array
    {
        $main = [];

        foreach ($cards as $card) {
            if ($card['sideboard']) {
                continue;
            }

            $main[$card['catalog_id']] = ($main[$card['catalog_id']] ?? 0) + $card['quantity'];
        }

        return $main;
    }
}
