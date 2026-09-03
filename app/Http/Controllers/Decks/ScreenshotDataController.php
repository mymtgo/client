<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\EncodeImageAsDataUrl;
use App\Actions\Decks\EncodeDeckScreenshotData;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\JsonResponse;

class ScreenshotDataController extends Controller
{
    public function __invoke(Deck $deck): JsonResponse
    {
        $deck->loadCount(['wonMatches', 'lostMatches']);

        $deckVersion = $deck->latestVersion;

        if (! $deckVersion) {
            return response()->json([
                'name' => $deck->name,
                'format' => $deck->format,
                'colorIdentity' => $deck->color_identity,
                'winRate' => 0,
                'matchesWon' => 0,
                'matchesLost' => 0,
                'coverArtBase64' => null,
                'nonLandCards' => [],
                'landCards' => [],
                'sideboardCards' => [],
                'cmcDistribution' => [],
                'typeDistribution' => [],
            ]);
        }

        $deckData = EncodeDeckScreenshotData::run($deckVersion);

        $totalMatches = $deck->won_matches_count + $deck->lost_matches_count;
        $winRate = $totalMatches > 0 ? round(($deck->won_matches_count / $totalMatches) * 100) : 0;

        $cover = $deck->cover;
        $coverArtBase64 = null;
        if ($cover) {
            $coverArtBase64 = EncodeImageAsDataUrl::run($cover->art_crop, $cover->local_art_crop);
        }

        return response()->json([
            'name' => $deck->name,
            'format' => $deck->format,
            'colorIdentity' => $deck->color_identity,
            'winRate' => $winRate,
            'matchesWon' => $deck->won_matches_count,
            'matchesLost' => $deck->lost_matches_count,
            'coverArtBase64' => $coverArtBase64,
            ...$deckData,
        ]);
    }
}
