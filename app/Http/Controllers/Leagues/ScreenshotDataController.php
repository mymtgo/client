<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Cards\EncodeImageAsDataUrl;
use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\JsonResponse;

class ScreenshotDataController extends Controller
{
    /**
     * Cover art for a league screenshot, fetched only when a screenshot is
     * actually taken. Inlining it into every league listing meant one blocking
     * image fetch per run on each page load.
     */
    public function __invoke(League $league): JsonResponse
    {
        $cover = $league->deckVersion?->deck?->cover;

        return response()->json([
            'coverArtBase64' => $cover ? EncodeImageAsDataUrl::run($cover->art_crop, $cover->local_art_crop) : null,
        ]);
    }
}
