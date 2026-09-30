<?php

namespace App\Actions\Cards;

use App\Actions\Settings\ApplyCardImagesPath;
use App\Models\Card;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadCardImage
{
    public static function run(Card $card): void
    {
        // With the chosen folder unreachable the disk points at the default
        // one, and images written there would be stranded when the drive
        // comes back. The card keeps its remote image until then.
        if (! $card->mtgo_id || ! ApplyCardImagesPath::available()) {
            return;
        }

        if ($card->image) {
            $card->local_image = self::download($card->image, "{$card->mtgo_id}.jpg");
        }

        if ($card->art_crop) {
            $card->local_art_crop = self::download($card->art_crop, "{$card->mtgo_id}_art.jpg");
        }

        if ($card->isDirty()) {
            $card->save();
        }
    }

    private static function download(string $url, string $path): ?string
    {
        try {
            $response = Http::timeout(15)->get($url);

            if (! $response->successful()) {
                return null;
            }

            Storage::disk('cards')->put($path, $response->body());

            return $path;
        } catch (\Throwable $e) {
            Log::warning("Failed to download card image: {$url}", ['error' => $e->getMessage()]);

            return null;
        }
    }
}
