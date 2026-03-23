<?php

namespace App\Actions\Matches;

class ParseMatchHistory
{
    /**
     * Attempt to find match results from MTGO's match_history file.
     *
     * @return array{wins: int, losses: int}|null Returns null if not found or file unavailable
     *
     * @todo Implement when match_history file format is documented.
     *       This is a placeholder — the file format needs investigation
     *       with real MTGO data samples before implementation.
     */
    public static function findResult(string $matchToken): ?array
    {
        // TODO: Implement match_history file parsing
        // For now, gracefully return null — matches stay in PendingResult
        // until manually resolved or this parser is implemented.
        return null;
    }
}
