<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use App\Models\Player;
use Native\Desktop\Facades\Settings;

class CreateMatchGames
{
    public static function run(MtgoMatch $match, array $games)
    {
        $username = Settings::get('mtgo_username');

        foreach ($games as $game) {
            $players = collect($game['entries'][0]['players']);

            $gameModel = Game::where('mtgo_id', $game['gameId'])->firstOrCreate([
                'match_id' => $match->id,
                'mtgo_id' => $game['gameId'],
            ], [
                'started_at' => $game['entries'][0]['timestamp'],
                'ended_at' => last($game['entries'])['timestamp'],
            ]);

            $playerModels = collect();
            $playerModelMapping = [];

            foreach ($players as $player) {
                $playerModel = Player::where('username', $player['username'])->firstOrCreate([
                    'username' => $player['username'],
                ]);

                $playerModels->push($playerModel);
                $playerModelMapping[$playerModel->id] = ['instance_id' => $player['id']];
            }

            $currentPlayer = $players->first(
                fn ($player) => $player['username'] == $username
            );

            $currentPlayerModel = $playerModels->first(
                fn ($player) => $player->username == $currentPlayer['username']
            );

            static::getPlayerDeck($gameModel, $currentPlayerModel, $game['deck']);

            $gameModel->players()->sync($playerModelMapping);

            $cards = last($game['entries'])['cards'];

            $opponents = collect($cards)->reject(
                fn ($card) => $card['ownerId'] == $currentPlayer['id']
            )->groupBy('ownerId');

            foreach ($opponents as $opponentCards) {
                $opponent = $players->first(
                    fn ($player) => $player['id'] == $opponentCards[0]['ownerId']
                );
                $opponentModel = $playerModels->first(
                    fn ($player) => $player->username == $opponent['username']
                );

                $gameDeck = new GameDeck;
                $gameDeck->player_id = $opponentModel->id;
                $gameDeck->game_id = $gameModel->id;
                $gameDeck->save();

                $cards = $opponentCards->groupBy('mtgoId')->map(function ($cards) {
                    return [
                        'card_id' => Card::firstOrCreate([
                            'mtgo_id' => $cards->first()['mtgoId'],
                        ])->id,
                        'quantity' => $cards->count(),
                        'sideboard' => false,
                    ];
                });

                $gameDeck->cards()->createMany($cards);
            }

        }
    }

    protected static function getPlayerDeck(Game $game, Player $player, array $cards): GameDeck
    {
        $gameDeck = new GameDeck;
        $gameDeck->player_id = $player->id;
        $gameDeck->game_id = $game->id;

        $cardModels = Card::whereIn('mtgo_id', collect($cards)->pluck('CatalogId')->toArray())->get();

        $deckCards = collect($cards)->map(
            fn ($card) => [
                'card_id' => $cardModels->first(
                    fn ($cardModel) => $cardModel->mtgo_id == $card['CatalogId']
                )?->id ?: Card::create([
                    'mtgo_id' => $card['CatalogId'],
                ])->id,
                'quantity' => $card['Quantity'],
                'sideboard' => (bool) $card['InSideboard'],
            ]
        )->toArray();

        $gameDeck->save();

        $gameDeck->cards()->createMany($deckCards);

        return $gameDeck;
    }
}
