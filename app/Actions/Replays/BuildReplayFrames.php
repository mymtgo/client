<?php

namespace App\Actions\Replays;

use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;

class BuildReplayFrames
{
    /**
     * A game's timeline as the replay viewer consumes it: every card carries
     * its name, type and image, and every player whether they are the local one.
     *
     * Portable mode is for snapshots that leave this machine. `image_url`
     * prefers the local card cache, which is served by this app's own
     * localhost server and is dead anywhere else, so portable frames carry
     * the card's remote https image or nothing.
     *
     * @return list<array{timestamp: string, content: array<string, mixed>}>
     */
    public static function run(Game $game, bool $portableImages = false): array
    {
        $timeline = GameTimeline::where('game_id', $game->id)->get();

        $catalogIds = $timeline->flatMap(
            fn (GameTimeline $event) => collect($event->content['Cards'] ?? [])->pluck('CatalogID')
        )->unique();

        $cardsByMtgoId = Card::whereIn('mtgo_id', $catalogIds)->get()->keyBy('mtgo_id');

        $localName = $game->localPlayers->first()?->username;

        return $timeline->map(function (GameTimeline $event) use ($cardsByMtgoId, $localName, $portableImages) {
            $content = $event->content;

            foreach ($content['Players'] as $i => $player) {
                $content['Players'][$i]['IsLocal'] = $localName === $player['Name'];
            }

            foreach ($content['Cards'] as $i => $card) {
                $cardModel = $cardsByMtgoId->get($card['CatalogID']);
                $content['Cards'][$i]['image'] = $cardModel === null ? null : self::image($cardModel, $portableImages);
                $content['Cards'][$i]['type'] = $cardModel?->type;
                $content['Cards'][$i]['name'] = $cardModel?->name;
            }

            return [
                'timestamp' => (string) $event->timestamp,
                'content' => $content,
            ];
        })->values()->all();
    }

    private static function image(Card $card, bool $portable): ?string
    {
        if (! $portable) {
            return $card->image_url;
        }

        $remote = $card->getRawOriginal('image');

        return is_string($remote) && str_starts_with($remote, 'https://') ? $remote : null;
    }
}
