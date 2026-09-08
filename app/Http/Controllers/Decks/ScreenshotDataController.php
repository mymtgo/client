<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\EncodeImageAsDataUrl;
use App\Actions\Decks\EncodeDeckScreenshotData;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Support\MatchRecord;
use Illuminate\Http\JsonResponse;

class ScreenshotDataController extends Controller
{
    public function __invoke(Deck $deck): JsonResponse
    {
        $deck->loadCount(['matches', 'wonMatches', 'lostMatches']);

        $matchRecord = MatchRecord::fromTotal(
            wins: (int) $deck->won_matches_count,
            losses: (int) $deck->lost_matches_count,
            total: (int) $deck->matches_count,
        );

        $deckVersion = $deck->latestVersion;

        if (! $deckVersion) {
            return response()->json([
                'name' => $deck->name,
                'format' => $deck->format,
                'colorIdentity' => $deck->color_identity,
                'matchRecord' => $matchRecord->toData(),
                'coverArtBase64' => null,
                'nonLandCards' => [],
                'landCards' => [],
                'sideboardCards' => [],
                'cmcDistribution' => [],
                'typeDistribution' => [],
            ]);
        }

        $deckData = EncodeDeckScreenshotData::run($deckVersion);

        $cover = $deck->cover;
        $coverArtBase64 = null;
        if ($cover) {
            $coverArtBase64 = EncodeImageAsDataUrl::run($cover->art_crop, $cover->local_art_crop);
        }

        return response()->json([
            'name' => $deck->name,
            'format' => $deck->format,
            'colorIdentity' => $deck->color_identity,
            'matchRecord' => $matchRecord->toData(),
            'coverArtBase64' => $coverArtBase64,
            ...$deckData,
        ]);
    }
}
