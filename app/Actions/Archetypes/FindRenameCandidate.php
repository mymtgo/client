<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use Illuminate\Support\Collection;

class FindRenameCandidate
{
    /**
     * Minimum score for a candidate to count as a plausible rename.
     */
    private const THRESHOLD = 0.45;

    /**
     * Tokens that describe colors rather than the deck itself. Sharing these
     * is weak evidence of a rename ("Mono-Green Tron" is not "Mono-Green
     * Titan"), so they carry far less weight than defining words.
     */
    private const WEAK_TOKENS = ['mono', 'white', 'blue', 'black', 'red', 'green', 'colorless', 'w', 'u', 'b', 'r', 'g'];

    /**
     * Find the most likely successor for a removed archetype among the given
     * candidates, based on name similarity within the same format. Returns
     * null when nothing scores above the threshold.
     *
     * @param  Collection<int, Archetype>  $candidates
     */
    public static function run(Archetype $removed, Collection $candidates): ?Archetype
    {
        return $candidates
            ->filter(fn (Archetype $candidate) => $candidate->format === $removed->format)
            ->map(fn (Archetype $candidate) => [
                'archetype' => $candidate,
                'score' => self::score($removed, $candidate),
            ])
            ->filter(fn (array $scored) => $scored['score'] >= self::THRESHOLD)
            ->sortByDesc('score')
            ->first()['archetype'] ?? null;
    }

    private static function score(Archetype $removed, Archetype $candidate): float
    {
        $a = self::normalize($removed->name);
        $b = self::normalize($candidate->name);

        $colorBonus = $removed->color_identity === $candidate->color_identity ? 0.15 : 0.0;

        if ($a === $b) {
            return 0.85 + $colorBonus;
        }

        $tokensA = collect(explode(' ', $a))->unique();
        $tokensB = collect(explode(' ', $b))->unique();

        $intersection = $tokensA->intersect($tokensB)->sum(self::tokenWeight(...));
        $union = $tokensA->merge($tokensB)->unique()->sum(self::tokenWeight(...));

        return 0.85 * ($intersection / max(1, $union)) + $colorBonus;
    }

    private static function tokenWeight(string $token): int
    {
        return in_array($token, self::WEAK_TOKENS, true) ? 1 : 3;
    }

    private static function normalize(string $name): string
    {
        $normalized = mb_strtolower($name);
        $normalized = (string) preg_replace('/[^a-z0-9]+/', ' ', $normalized);

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }
}
