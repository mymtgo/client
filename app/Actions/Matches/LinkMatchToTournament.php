<?php

namespace App\Actions\Matches;

use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class LinkMatchToTournament
{
    /**
     * Populate the tournament_token on a match by looking up a round_info
     * log event that mentions this match. Idempotent: no-op if already linked.
     *
     * Called in two places:
     *   - AdvanceMatchState, right after match creation (handles the
     *     "round_info arrived before match" order).
     *   - RunPipeline backfill pass, each tick over matches still missing a
     *     token (handles the "match created before round_info" order).
     */
    public static function run(MtgoMatch $match): void
    {
        if ($match->tournament_token !== null) {
            return;
        }

        if ($match->tournament_event_id === null) {
            return;
        }

        $raw = LogEvent::query()
            ->where('event_type', LogEventType::TOURNAMENT_ROUND_INFO->value)
            ->where('raw_text', 'like', '%'.$match->token.'%')
            ->orderByDesc('id')
            ->value('raw_text');

        if ($raw === null) {
            return;
        }

        // The round_info payload's outer JSON carries the tournament token as
        // "Token":"...". Use regex rather than json_decode because MTGO can ship
        // malformed JSON (see MEMORY.md: mtgo_malformed_json_trap).
        if (! preg_match('/"Token"\s*:\s*"(?<token>[a-f0-9\-]{36})"/i', $raw, $m)) {
            return;
        }

        $match->update(['tournament_token' => $m['token']]);
    }
}
