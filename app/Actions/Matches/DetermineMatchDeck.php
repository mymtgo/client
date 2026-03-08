<?php

namespace App\Actions\Matches;

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Util\ExtractJson;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class DetermineMatchDeck
{
    public static function run(MtgoMatch $match)
    {
        $games = $match->games()->orderBy('started_at')->pluck('mtgo_id');

        $decksEvents = LogEvent::where('event_type', 'deck_used')
            ->whereIn('game_id', $games)->orderBy('logged_at', 'asc')->get();

        $firstGameId = $games->first();

        $firstGameDeck = $decksEvents->first(
            fn ($event) => (int) $event->game_id == $firstGameId
        );

        $firstGameDeckCards = collect(ExtractJson::run($firstGameDeck->raw_text)->first())->map(function ($card) {
            return [
                'mtgo_id' => $card['CatalogId'],
                'quantity' => $card['Quantity'],
                'sideboard' => $card['InSideboard'] ? 'true' : 'false',
            ];
        });

        $signature = GenerateDeckSignature::run($firstGameDeckCards);

        $accountId = Account::where('username', Mtgo::getUsername())->value('id');

        $deckVersion = DeckVersion::where('signature', $signature)
            ->when($accountId, fn ($q) => $q->whereHas('deck', fn ($q2) => $q2->where('account_id', $accountId)))
            ->first();

        $match->update([
            'deck_version_id' => $deckVersion?->id,
        ]);
    }
}
