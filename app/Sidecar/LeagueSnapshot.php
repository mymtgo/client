<?php

namespace App\Sidecar;

final readonly class LeagueSnapshot
{
    /**
     * @param  list<string>|null  $historyMatchIds  distinct match ids in MTGO's run history; null means "could not read", never "no games"
     */
    public function __construct(
        public ?int $eventId,
        public ?string $token,
        public ?int $totalMatches,
        public ?int $wins,
        public ?int $losses,
        public ?int $matchNumber,
        public ?int $matchesRemaining,
        public ?array $historyMatchIds,
    ) {}

    /** Null for anything that is not an array: sidecar payloads are hostile input. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        return new self(
            eventId: self::int($data['event_id'] ?? null),
            token: is_string($data['token'] ?? null) ? $data['token'] : null,
            totalMatches: self::int($data['total_matches'] ?? null),
            wins: self::int($data['wins'] ?? null),
            losses: self::int($data['losses'] ?? null),
            matchNumber: self::int($data['match_number'] ?? null),
            matchesRemaining: self::int($data['matches_remaining'] ?? null),
            historyMatchIds: self::historyMatchIds($data['game_history'] ?? null),
        );
    }

    /**
     * First match of a run. The single place the probe's counter-timing
     * answer (spec section 4, question 1) lands: until then match_number is
     * read as 1-based and counting the current match, which is draw-safe
     * where wins + losses is not.
     */
    public function isNewRun(): ?bool
    {
        return $this->matchNumber === null ? null : $this->matchNumber <= 1;
    }

    /** Matches the run played before the current one; null when it cannot tell. */
    public function priorMatchCount(): ?int
    {
        return $this->matchNumber === null ? null : max(0, $this->matchNumber - 1);
    }

    /**
     * Interim completion rule for an ended snapshot (spec 5.3, pending probe
     * question 3).
     */
    public function showsRunFinished(): bool
    {
        if ($this->matchesRemaining === 0) {
            return true;
        }

        return $this->matchNumber !== null && $this->totalMatches !== null && $this->matchNumber >= $this->totalMatches;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * A game history holds one row per game, so match ids repeat; rows the
     * SDK could not read carry no match id and are skipped.
     *
     * @return list<string>|null
     */
    private static function historyMatchIds(mixed $history): ?array
    {
        if (! is_array($history)) {
            return null;
        }

        $ids = [];

        foreach ($history as $game) {
            if (is_array($game) && is_int($game['match_id'] ?? null)) {
                $ids[] = (string) $game['match_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
