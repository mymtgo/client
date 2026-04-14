<?php

namespace App\Actions\Import;

use App\Models\Card;
use App\Models\DeckVersion;

class SuggestDeckForMatch
{
    private const MIN_CONFIDENCE = 0.6;

    /**
     * Suggest the best matching DeckVersion for the given cards seen in a game log.
     *
     * @param  array<int, array{mtgo_id: int, name: string}>  $localCards
     * @return array{deck_version_id: int, deck_name: string, confidence: float, deck_deleted: bool}|null
     */
    public static function run(array $localCards): ?array
    {
        if (empty($localCards)) {
            return null;
        }

        $mtgoIds = array_column($localCards, 'mtgo_id');
        $oracleIds = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id')
            ->unique()
            ->values()
            ->toArray();

        if (empty($oracleIds)) {
            return null;
        }

        $versions = DeckVersion::with(['deck' => fn ($q) => $q->withTrashed()])->get();
        $bestMatch = null;
        $bestScore = 0;

        foreach ($versions as $version) {
            $deckOracleIds = collect($version->cards)->pluck('oracle_id')->toArray();

            if (empty($deckOracleIds)) {
                continue;
            }

            $overlap = count(array_intersect($oracleIds, $deckOracleIds));
            $score = $overlap / count($oracleIds);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $version;
            }
        }

        if ($bestMatch === null || $bestScore < self::MIN_CONFIDENCE) {
            return null;
        }

        return [
            'deck_version_id' => $bestMatch->id,
            'deck_name' => $bestMatch->deck->name ?? 'Unknown Deck',
            'confidence' => round($bestScore, 2),
            'deck_deleted' => $bestMatch->deck->trashed() ?? false,
        ];
    }
}
