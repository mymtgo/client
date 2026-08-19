<?php

namespace App\Jobs;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Models\CardStatShipQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestoreLogDerivedCardStats implements ShouldQueue
{
    use Queueable;

    /**
     * Log-derived counters a frozen payload can restore. Kept/seen/quantity
     * derive from GameCards snapshots and survive recomputation untouched.
     *
     * @var list<string>
     */
    private const LOG_FIELDS = ['cast', 'played', 'kicked', 'flashback', 'madness', 'evoked', 'activated'];

    /**
     * Repair games whose log-derived counters were zeroed by a recompute that
     * had no usable game-log source for them. The ship queue froze each game's
     * stats at enqueue time, so it is the only surviving record of those
     * counters once log_events are pruned and the .dat has rotated away.
     *
     * Runs on the same queue as the recompute jobs so FIFO ordering puts it
     * behind them — running inline would inspect data the queued recomputes
     * have not written yet.
     */
    public function handle(): void
    {
        $restored = 0;

        CardStatShipQueue::query()
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$restored) {
                foreach ($rows as $row) {
                    $restored += $this->restoreGame($row);
                }
            });

        Log::channel('pipeline')->info("RestoreLogDerivedCardStats: restored counters for {$restored} card rows");
    }

    private function restoreGame(CardStatShipQueue $row): int
    {
        $payload = is_array($row->payload) ? $row->payload : json_decode((string) $row->payload, true);
        $payloadCards = collect($payload['cards'] ?? [])->keyBy('oracle_id');

        if ($payloadCards->isEmpty()) {
            return 0;
        }

        // Only touch games the recompute left with no log signal at all. A game
        // holding any counter was rebuilt from a real log and is authoritative
        // — the frozen payload predates the extraction fixes.
        $signalExpression = collect(ExtractCardsFromGameLog::COUNTER_FIELDS)
            ->map(fn (string $field) => "COALESCE(\"{$field}\", 0)")
            ->implode(' + ');

        $hasSignal = DB::table('card_game_stats')
            ->where('game_id', $row->game_id)
            ->where('opponent', false)
            ->whereRaw("({$signalExpression}) > 0")
            ->exists();

        if ($hasSignal) {
            return 0;
        }

        $stats = DB::table('card_game_stats')
            ->where('game_id', $row->game_id)
            ->where('opponent', false)
            ->get(['id', 'oracle_id']);

        $restored = 0;

        foreach ($stats as $stat) {
            $card = $payloadCards->get($stat->oracle_id);

            if ($card === null) {
                continue;
            }

            $values = [];
            foreach (self::LOG_FIELDS as $field) {
                $values[$field] = (int) ($card[$field] ?? 0);
            }

            if (array_sum($values) === 0) {
                continue;
            }

            DB::table('card_game_stats')
                ->where('id', $stat->id)
                ->update($values + ['updated_at' => now()]);

            $restored++;
        }

        return $restored;
    }
}
