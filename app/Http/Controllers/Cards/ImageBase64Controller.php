<?php

namespace App\Http\Controllers\Cards;

use App\Actions\Cards\EncodeImageAsDataUrl;
use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\JsonResponse;

class ImageBase64Controller extends Controller
{
    public function __invoke(string $oracleId): JsonResponse
    {
        $card = Card::query()
            ->where('oracle_id', $oracleId)
            ->whereNotNull('oracle_id')
            ->first();

        if (! $card) {
            return response()->json(['dataUrl' => null]);
        }

        return response()->json([
            'dataUrl' => EncodeImageAsDataUrl::run($card->image, $card->local_image),
        ]);
    }
}
