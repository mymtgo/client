<?php

namespace App\Actions\Matches;

use App\Actions\Decks\FindDeckVersionByOracleIdentity;
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
            Log::channel('pipeline')->info('DetermineMatchDeck: no games yet', [
                'match_id' => $match->id,
                'mtgo_id' => $match->mtgo_id,
                'tournament_round' => $match->tournament_round,
            ]);

            return;
        }

        $decksEvents = LogEvent::where('event_type', 'deck_used')
            ->whereIn('game_id', $games)->orderBy('logged_at', 'asc')->get();

        $firstGameId = $games->first();

        $firstGameDeck = $decksEvents->first(
            fn ($event) => (int) $event->game_id == $firstGameId
        );

        if (! $firstGameDeck) {
            // Silent until now, which made a permanently null deck_version_id
            // indistinguishable from a signature miss. Record which game ids
            // the match owns vs which ones actually carry a deck_used event.
            Log::channel('pipeline')->info('DetermineMatchDeck: no deck_used event for first game', [
                'match_id' => $match->id,
                'mtgo_id' => $match->mtgo_id,
                'tournament_round' => $match->tournament_round,
                'first_game_id' => $firstGameId,
                'match_game_ids' => $games->all(),
                'deck_used_game_ids' => $decksEvents->pluck('game_id')->all(),
            ]);

            return;
        }

        $deckCards = ExtractJson::run($firstGameDeck->raw_text)->first();

        if (empty($deckCards)) {
            Log::channel('pipeline')->info('DetermineMatchDeck: deck_used event carried no cards', [
                'match_id' => $match->id,
                'mtgo_id' => $match->mtgo_id,
                'tournament_round' => $match->tournament_round,
                'log_event_id' => $firstGameDeck->id,
                'text_preview' => mb_substr($firstGameDeck->raw_text, 0, 200),
            ]);

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

        $deckVersion = DeckVersion::query()
            ->forAccount($accountId)
            ->where('signature', $signature)
            ->first();

        // Signatures are printing-specific. When MTGO plays a different
        // printing than the .dek file records — reinstalls, collection changes —
        // the exact lookup misses even though it is the same 75 cards.
        if (! $deckVersion) {
            $deckVersion = FindDeckVersionByOracleIdentity::run($firstGameDeckCards, $accountId);

            if ($deckVersion) {
                Log::channel('pipeline')->info('DetermineMatchDeck: linked by oracle identity', [
                    'match_id' => $match->id,
                    'mtgo_id' => $match->mtgo_id,
                    'deck_version_id' => $deckVersion->id,
                ]);
            }
        }

        if (! $deckVersion) {
            Log::channel('pipeline')->info('DetermineMatchDeck: no deck version match', [
                'match_id' => $match->id,
                'mtgo_id' => $match->mtgo_id,
                'tournament_round' => $match->tournament_round,
                'account_id' => $accountId,
                'card_count' => $firstGameDeckCards->count(),
                'computed_signature' => $signature,
                'candidate_versions' => DeckVersion::query()->forAccount($accountId)->count(),
            ]);
        }

        $match->update([
            'deck_version_id' => $deckVersion?->id,
        ]);
    }
}
