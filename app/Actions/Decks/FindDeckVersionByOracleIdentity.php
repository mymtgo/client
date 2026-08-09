<?php

namespace App\Actions\Decks;

use App\Models\Card;
use App\Models\DeckVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FindDeckVersionByOracleIdentity
{
    /**
     * Normalised signature per version, keyed by id and raw signature so an
     * edited version can never serve a stale entry.
     *
     * RelinkOrphanMatches re-runs every pipeline tick over matches that may
     * never link, and each attempt would otherwise re-decode every version the
     * account owns.
     *
     * @var array<string, string|null>
     */
    private static array $memo = [];

    /**
     * Find the deck version whose card list matches by oracle identity rather
     * than by MTGO catalog id.
     *
     * Deck signatures are built from CatalogIds, which are printing-specific:
     * the same card from a different set is a different id, so a deck holding
     * a swapped printing produces a signature that matches nothing and the
     * match never links. MTGO reshuffles which printings a saved deck file
     * references (reinstalls, collection changes, and some cards are logged
     * under a different printing than the one in the .dek).
     *
     * Only used as a fallback after the exact signature lookup misses, so the
     * common path stays a single indexed read.
     *
     * @param  Collection<int, array{mtgo_id: int|string, quantity: int|string, sideboard: string}>  $cards
     */
    public static function run(Collection $cards, ?int $accountId): ?DeckVersion
    {
        $wanted = self::oracleSignature($cards, self::oracleMap($cards->pluck('mtgo_id')->all()));

        if ($wanted === null) {
            return null;
        }

        $candidates = DeckVersion::query()->forAccount($accountId)->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Only resolved signatures are memoised: a null means some card had no
        // oracle_id yet, and PopulateMissingCardData fills those in later.
        $uncached = $candidates->reject(fn (DeckVersion $version) => isset(self::$memo[self::memoKey($version)]));

        // One lookup for every catalog id across every candidate still needing
        // a signature, so the comparison below costs no further queries.
        $oracleMap = self::oracleMap(
            $uncached->flatMap(fn (DeckVersion $version) => self::decode($version->signature)->pluck('mtgo_id'))->all()
        );

        foreach ($uncached as $version) {
            $normalized = self::oracleSignature(self::decode($version->signature), $oracleMap);

            if ($normalized !== null) {
                self::$memo[self::memoKey($version)] = $normalized;
            }
        }

        $matches = $candidates->filter(
            fn (DeckVersion $version) => (self::$memo[self::memoKey($version)] ?? null) === $wanted
        );

        if ($matches->isEmpty()) {
            return null;
        }

        // Versions of one deck that differ only by printing are the same list,
        // so the newest wins. Across different decks there is no safe answer —
        // leave the match unlinked rather than attribute it to the wrong deck.
        if ($matches->pluck('deck_id')->unique()->count() > 1) {
            Log::channel('pipeline')->info('FindDeckVersionByOracleIdentity: ambiguous across decks', [
                'deck_ids' => $matches->pluck('deck_id')->unique()->values()->all(),
                'version_ids' => $matches->pluck('id')->values()->all(),
            ]);

            return null;
        }

        return $matches->sortByDesc('modified_at')->first();
    }

    private static function memoKey(DeckVersion $version): string
    {
        return $version->id.':'.$version->signature;
    }

    /**
     * Card rows keyed by catalog id. Cards whose oracle_id has not been
     * resolved yet are absent, which forces a null signature below.
     *
     * @param  array<int, int|string>  $mtgoIds
     * @return Collection<int, string>
     */
    private static function oracleMap(array $mtgoIds): Collection
    {
        return Card::query()
            ->whereIn('mtgo_id', collect($mtgoIds)->map(fn ($id) => (int) $id)->unique()->values())
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id', 'mtgo_id');
    }

    /**
     * Printing-independent signature. Returns null when any card is missing an
     * oracle_id — a partial normalisation would match the wrong list.
     *
     * @param  Collection<int, array{mtgo_id: int|string, quantity: int|string, sideboard: string}>  $cards
     * @param  Collection<int, string>  $oracleMap
     */
    private static function oracleSignature(Collection $cards, Collection $oracleMap): ?string
    {
        if ($cards->isEmpty()) {
            return null;
        }

        $normalized = [];

        foreach ($cards as $card) {
            $oracleId = $oracleMap->get((int) $card['mtgo_id']);

            if ($oracleId === null) {
                return null;
            }

            $sideboard = filter_var($card['sideboard'], FILTER_VALIDATE_BOOL) ? 'true' : 'false';
            $key = "{$oracleId}:{$sideboard}";

            // Two printings of the same card collapse into one entry, so their
            // quantities have to be summed rather than listed separately.
            $normalized[$key] = ($normalized[$key] ?? 0) + (int) $card['quantity'];
        }

        ksort($normalized);

        return collect($normalized)->map(fn ($quantity, $key) => "{$key}:{$quantity}")->join('|');
    }

    /**
     * Decode a stored signature back into card rows. Legacy signatures are
     * already oracle-keyed; those cannot be normalised here and are skipped.
     *
     * @return Collection<int, array{mtgo_id: int|string, quantity: int|string, sideboard: string}>
     */
    private static function decode(?string $signature): Collection
    {
        $decoded = $signature ? base64_decode($signature, true) : false;

        if ($decoded === false) {
            return collect();
        }

        return collect(explode('|', $decoded))
            ->filter()
            ->map(function (string $segment) {
                $parts = explode(':', $segment);

                if (count($parts) < 3 || ! is_numeric($parts[0])) {
                    return null;
                }

                return [
                    'mtgo_id' => (int) $parts[0],
                    'quantity' => (int) $parts[1],
                    'sideboard' => $parts[2],
                ];
            })
            ->filter()
            ->values();
    }
}
