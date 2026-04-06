<?php

namespace App\Jobs;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Import\ScoreMatchConfidence;
use App\Actions\Matches\ParseGameHistory;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\ImportScan;
use App\Models\ImportScanMatch;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MatchAndScoreJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $scanId,
    ) {
        $this->onQueue('importer');
    }

    public function handle(): void
    {
        $scan = ImportScan::find($this->scanId);

        if (! $scan || $scan->isCancelled()) {
            return;
        }

        $scan->markStage('scoring', $scan->total);

        // Re-parse and re-filter (fast, avoids inter-job data passing)
        $historyRecords = app()->bound('import.history_records')
            ? app('import.history_records')
            : ParseGameHistory::parse();

        $existingMtgoIds = MtgoMatch::pluck('mtgo_id')->filter()->toArray();
        $newRecords = array_values(array_filter(
            $historyRecords,
            fn ($r) => ! in_array($r['Id'], $existingMtgoIds)
        ));

        if (empty($newRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);

            return;
        }

        $deckVersion = DeckVersion::find($scan->deck_version_id);

        $batches = array_chunk($newRecords, 500);
        $processed = 0;

        foreach ($batches as $batch) {
            if ($scan->fresh()->isCancelled()) {
                return;
            }

            $rows = [];

            foreach ($batch as $record) {
                $matchedLog = $this->findMatchingLog($record);
                $confidence = null;
                $localPlayer = null;

                if ($matchedLog && $deckVersion) {
                    $cardData = ExtractCardsFromGameLog::run($matchedLog->decoded_entries);
                    $opponent = $record['Opponents'][0] ?? null;
                    $localPlayer = collect($cardData['players'])->first(fn ($p) => $p !== $opponent) ?? $cardData['players'][0] ?? null;
                    $localMtgoIds = collect($cardData['cards_by_player'][$localPlayer] ?? [])->pluck('mtgo_id')->toArray();

                    if (! empty($localMtgoIds)) {
                        $confidence = ScoreMatchConfidence::run($localMtgoIds, $deckVersion);
                    }
                }

                $wins = $record['GameWins'];
                $losses = $record['GameLosses'];

                $rows[] = [
                    'import_scan_id' => $scan->id,
                    'history_id' => $record['Id'],
                    'started_at' => $record['StartTime'],
                    'opponent' => $record['Opponents'][0] ?? 'Unknown',
                    'format' => $record['Format'] ?? '',
                    'format_display' => MtgoMatch::displayFormat($record['Format'] ?? ''),
                    'games_won' => $wins,
                    'games_lost' => $losses,
                    'outcome' => $wins > $losses ? 'win' : ($wins < $losses ? 'loss' : 'draw'),
                    'game_log_token' => $matchedLog?->match_token,
                    'confidence' => $confidence,
                    'round' => $record['Round'] ?? 0,
                    'description' => $record['Description'] ?? '',
                    'game_ids' => json_encode($record['GameIds'] ?? []),
                    'local_player' => $localPlayer,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            ImportScanMatch::insert($rows);
            $processed += count($batch);
            $scan->update(['progress' => $processed]);
        }

        $scan->update(['status' => 'complete']);
    }

    private function findMatchingLog(array $record): ?GameLog
    {
        $opponent = $record['Opponents'][0] ?? null;

        if (! $opponent) {
            return null;
        }

        $historyStart = Carbon::parse($record['StartTime']);

        return GameLog::whereNotNull('decoded_entries')
            ->whereDoesntHave('match')
            ->where('first_timestamp', '>=', $historyStart->copy()->subMinutes(5))
            ->where('first_timestamp', '<=', $historyStart->copy()->addMinutes(5))
            ->where('players', 'LIKE', '%"'.$opponent.'"%')
            ->first();
    }

    public function failed(\Throwable $e): void
    {
        ImportScan::find($this->scanId)?->markFailed($e->getMessage());

        Log::channel('pipeline')->error('MatchAndScoreJob failed', [
            'scan_id' => $this->scanId,
            'error' => $e->getMessage(),
        ]);
    }
}
