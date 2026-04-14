<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\GameLog;
use App\Models\ImportScanMatch;
use Illuminate\Http\JsonResponse;

class ScanMatchCardsController extends Controller
{
    public function __invoke(ImportScanMatch $match): JsonResponse
    {
        if (! $match->game_log_token) {
            return response()->json(['local_cards' => [], 'opponent_cards' => []]);
        }

        $gameLog = GameLog::where('match_token', $match->game_log_token)
            ->whereNotNull('decoded_entries')
            ->first();

        if (! $gameLog?->decoded_entries) {
            return response()->json(['local_cards' => [], 'opponent_cards' => []]);
        }

        $cardData = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
        $localPlayer = $match->local_player;
        $opponent = $match->opponent;

        $localCards = $cardData['cards_by_player'][$localPlayer] ?? [];
        $opponentCards = $cardData['cards_by_player'][$opponent] ?? [];

        // Enrich with names from DB where available
        $allMtgoIds = collect($localCards)->merge($opponentCards)->pluck('mtgo_id')->unique()->toArray();
        $cardNames = Card::whereIn('mtgo_id', $allMtgoIds)->pluck('name', 'mtgo_id');

        $enrich = fn (array $cards) => collect($cards)->map(fn ($c) => [
            'mtgo_id' => $c['mtgo_id'],
            'name' => $cardNames[$c['mtgo_id']] ?? $c['name'] ?? 'Unknown',
        ])->sortBy('name')->values()->toArray();

        return response()->json([
            'local_cards' => $enrich($localCards),
            'opponent_cards' => $enrich($opponentCards),
        ]);
    }
}
