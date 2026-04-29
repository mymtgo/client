<?php

namespace App\Jobs;

use App\Actions\Decks\GenerateDeckSignature;
use App\Models\Card;
use App\Models\DeckVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched synchronously by App\Updates\RegenerateDeckSignatures so any
 * exception bubbles to RunAppUpdates and the update is retried on next boot.
 * Implements ShouldQueue purely to keep dispatch ergonomics consistent with
 * the rest of the codebase; do not dispatch this job async.
 */
class RegenerateDeckSignaturesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        DeckVersion::query()->lazyById(100)->each(function (DeckVersion $version) {
            $cards = $this->translateLegacySignature($version->signature);

            if ($cards === null) {
                return;
            }

            $newSignature = GenerateDeckSignature::run(collect($cards));

            if ($newSignature !== $version->signature) {
                $version->signature = $newSignature;
                $version->saveQuietly();
            }
        });
    }

    /**
     * Decode a legacy oracle_id-anchored signature and translate to
     * mtgo_id-anchored card list. Returns null if any card cannot be resolved
     * or if the signature is already canonical.
     *
     * @return array<int, array{mtgo_id:int, quantity:int, sideboard:string}>|null
     */
    private function translateLegacySignature(?string $signature): ?array
    {
        if (! $signature) {
            return null;
        }

        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return null;
        }

        $segments = collect(explode('|', $decoded))->filter()->map(function (string $part) {
            $bits = explode(':', $part);

            return count($bits) >= 3 ? $bits : null;
        })->filter()->values();

        if ($segments->isEmpty()) {
            return null;
        }

        // Already canonical (numeric first segment) — caller still re-runs
        // GenerateDeckSignature so idempotent recompute can no-op.
        if (is_numeric($segments->first()[0])) {
            $cards = $segments->map(fn ($parts) => [
                'mtgo_id' => (int) $parts[0],
                'quantity' => (int) $parts[1],
                'sideboard' => $parts[2],
            ])->toArray();

            return $cards;
        }

        $oracleIds = $segments->map(fn ($parts) => $parts[0])->unique()->values();

        $mtgoByOracle = Card::whereIn('oracle_id', $oracleIds)
            ->get(['mtgo_id', 'oracle_id'])
            ->keyBy('oracle_id');

        if ($mtgoByOracle->count() < $oracleIds->count()) {
            Log::channel('pipeline')->warning('RegenerateDeckSignaturesJob: unresolved cards, skipping row', [
                'requested' => $oracleIds->count(),
                'resolved' => $mtgoByOracle->count(),
            ]);

            return null;
        }

        return $segments->map(fn ($parts) => [
            'mtgo_id' => (int) $mtgoByOracle->get($parts[0])->mtgo_id,
            'quantity' => (int) $parts[1],
            'sideboard' => $parts[2],
        ])->toArray();
    }
}
