<?php

namespace App\Actions\Matches;

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Util\ExtractJson;
use App\Models\Account;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class DetermineMatchDeck
{
    public static function run(MtgoMatch $match)
    {
        $games = $match->games()->orderBy('started_at')->pluck('mtgo_id');

        if ($games->isEmpty()) {
            return;
        }

        $decksEvents = LogEvent::where('event_type', 'deck_used')
            ->whereIn('game_id', $games)->orderBy('logged_at', 'asc')->get();

        $firstGameId = $games->first();

        $firstGameDeck = $decksEvents->first(
            fn ($event) => (int) $event->game_id == $firstGameId
        );

        if (! $firstGameDeck) {
            return;
        }

        $deckCards = ExtractJson::run($firstGameDeck->raw_text)->first();

        if (empty($deckCards)) {
            return;
        }

        $firstGameDeckCards = collect($deckCards)->map(function ($card) {
            return [
                'mtgo_id' => $card['CatalogId'],
                'quantity' => $card['Quantity'],
                'sideboard' => $card['InSideboard'] ? 'true' : 'false',
            ];
        });

        $signature = GenerateDeckSignature::run($firstGameDeckCards);

        $accountId = Account::currentId();

        $applyAccountScope = fn ($query) => $query->when(
            $accountId,
            fn ($q) => $q->whereHas('deck', fn ($q2) => $q2->where('account_id', $accountId))
        );

        $deckVersion = $applyAccountScope(DeckVersion::where('signature', $signature))->first();

        if (! $deckVersion) {
            Log::channel('pipeline')->info('DetermineMatchDeck: no deck version match', [
                'match_id' => $match->id,
                'mtgo_id' => $match->mtgo_id,
                'account_id' => $accountId,
                'card_count' => $firstGameDeckCards->count(),
                'computed_signature' => $signature,
                'candidate_versions' => $applyAccountScope(DeckVersion::query())->count(),
            ]);
        }

        $match->update([
            'deck_version_id' => $deckVersion?->id,
        ]);
    }
}
