<?php

namespace App\Http\Controllers\Cards;

use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

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
            'dataUrl' => $this->toBase64($card->image, $card->local_image),
        ]);
    }

    private function toBase64(?string $url, ?string $localStoragePath): ?string
    {
        if (! $url && ! $localStoragePath) {
            return null;
        }

        try {
            if ($localStoragePath && Storage::disk('cards')->exists($localStoragePath)) {
                $contents = Storage::disk('cards')->get($localStoragePath);
            } else {
                if (! $url) {
                    return null;
                }
                $contents = file_get_contents($url);
            }

            if ($contents === false || $contents === null) {
                return null;
            }

            $source = $localStoragePath ?? $url ?? '';
            $mime = 'image/jpeg';
            if (str_contains($source, '.png')) {
                $mime = 'image/png';
            } elseif (str_contains($source, '.webp')) {
                $mime = 'image/webp';
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return null;
        }
    }
}
