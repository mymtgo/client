<?php

namespace App\Actions\Compile;

use App\Actions\Archive\WriteRawArchive;
use App\Actions\Outbox\EnqueueMatch;
use App\Jobs\PushOutboxJob;
use App\Models\LogEvent;
use App\Models\Outbox;

/**
 * Activity-based push triggers — never end-gated. Candidate tokens are
 * those with real game traffic; a token is compiled once its traffic has
 * been quiet for the debounce window (mid-burst compiles are wasted work —
 * a late event just recompiles and last-write-wins upstream).
 *
 * Idempotence is layered: EnqueueMatch no-ops on identical payloads, and a
 * token whose newest event predates its last compile is skipped entirely.
 * Runs from the scheduler (periodic flush) and the app-close flush; the
 * event-driven ingest tick (Task 2b) will call it too.
 */
final class PushTriggers
{
    /** Seconds of token quiet before a (re)compile is worth doing. */
    public const DEBOUNCE_SECONDS = 30;

    public function __construct(
        private CompileMatch $compile,
        private WriteRawArchive $archive,
        private EnqueueMatch $enqueue,
    ) {}

    public function run(): void
    {
        $enqueued = false;

        foreach ($this->quietCandidateTokens() as $token) {
            $dto = $this->compile->run($token);

            if ($dto === null) {
                continue;
            }

            $row = $this->enqueue->run($dto);

            if ($row->wasRecentlyCreated || $row->wasChanged()) {
                $this->archive->run($token, $this->rawSegment($token));
                $enqueued = true;
            }
        }

        if ($enqueued || Outbox::pending()->exists()) {
            PushOutboxJob::dispatch();
        }
    }

    /**
     * Tokens with game traffic whose newest event is (a) outside the
     * debounce window and (b) newer than the last compile of that token.
     *
     * @return array<int, string>
     */
    private function quietCandidateTokens(): array
    {
        $candidates = LogEvent::query()
            ->where('event_type', 'game_management_json')
            ->whereNotNull('game_id')
            ->whereNotNull('match_token')
            ->groupBy('match_token')
            ->selectRaw('match_token, MAX(created_at) as last_event_at')
            ->pluck('last_event_at', 'match_token');

        $compiledAt = Outbox::query()
            ->whereIn('match_key', $candidates->keys())
            ->pluck('updated_at', 'match_key');

        return $candidates
            ->filter(function ($lastEventAt, string $token) use ($compiledAt) {
                if (now()->subSeconds(self::DEBOUNCE_SECONDS)->lt($lastEventAt)) {
                    return false; // still active — wait for quiet
                }

                $last = $compiledAt->get($token);

                return $last === null || $last->lt($lastEventAt); // new bytes since last compile
            })
            ->keys()
            ->all();
    }

    private function rawSegment(string $token): string
    {
        return LogEvent::query()
            ->where('match_token', $token)
            ->orderBy('timestamp')
            ->pluck('raw_text')
            ->implode("\n");
    }
}
