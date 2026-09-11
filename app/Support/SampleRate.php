<?php

namespace App\Support;

/**
 * A count and the sample it was drawn from, kept together.
 *
 * Rates get promoted to recommendations all over this app, and a rate on its
 * own cannot say whether it earned one: 1 of 1 game and 400 of 800 both read
 * as percentages. Pairing the two means a caller cannot reach the percentage
 * without the denominator being in hand.
 *
 * The thresholds themselves are policy and belong to the feature applying
 * them; this only answers whether a given floor is met.
 */
final readonly class SampleRate
{
    private function __construct(
        public int $count,
        public int $games,
    ) {}

    public static function of(?int $count, ?int $games): self
    {
        return new self(max(0, $count ?? 0), max(0, $games ?? 0));
    }

    public static function empty(): self
    {
        return new self(0, 0);
    }

    /**
     * The count as a whole percentage of its sample, or null when there is no
     * sample — which is a different statement from "this never happens" and
     * must not read as 0%.
     */
    public function percentage(): ?int
    {
        if ($this->games <= 0) {
            return null;
        }

        return (int) round($this->count / $this->games * 100);
    }

    /** Whether the sample is large enough for its rate to be acted on. */
    public function isConfident(int $minimumGames): bool
    {
        return $this->games > 0 && $this->games >= $minimumGames;
    }

    /** Whether the rate both clears the share and rests on a big enough sample. */
    public function supports(int $minimumShare, int $minimumGames): bool
    {
        return $this->isConfident($minimumGames) && ($this->percentage() ?? 0) >= $minimumShare;
    }
}
