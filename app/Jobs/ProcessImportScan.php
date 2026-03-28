<?php

namespace App\Jobs;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Import\PopulateCardsInChunks;
use App\Actions\Import\ScoreMatchConfidence;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameHistory;
use App\Actions\Matches\ParseGameLogBinary;
use App\Actions\Pipeline\DiscoverGameLogs;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\ImportScan;
use App\Models\ImportScanMatch;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessImportScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

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

        try {
            $this->process($scan);
        } catch (\Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::channel('pipeline')->error('ProcessImportScan failed', [
                'scan_id' => $this->scanId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function process(ImportScan $scan): void
    {
        // Step 1: Discover all game logs on disk (historical ones too)
        DiscoverGameLogs::discoverAll();

        // Step 2: Backfill any undecoded game logs
        $this->backfillGameLogs($scan);

        if ($scan->fresh()->isCancelled()) {
            return;
        }

        // Step 3: Parse history file
        $historyRecords = ParseGameHistory::parse();

        if (empty($historyRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);

            return;
        }

        // Step 4: Filter out matches already in DB
        $existingMtgoIds = MtgoMatch::pluck('mtgo_id')->filter()->toArray();
        $newRecords = array_values(array_filter(
            $historyRecords,
            fn ($r) => ! in_array($r['Id'], $existingMtgoIds)
        ));

        if (empty($newRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);

            return;
        }

        $scan->update(['total' => count($newRecords)]);

        // Step 5: Build game log index from DB
        $logIndex = $this->buildGameLogIndex();

        // Step 6: Populate cards (needed for confidence scoring)
        PopulateCardsInChunks::run();

        // Step 7: Load deck version for scoring
        $deckVersion = DeckVersion::find($scan->deck_version_id);

        // Step 8: Match + score in batches
        $batches = array_chunk($newRecords, 500);
        $processed = 0;

        foreach ($batches as $batch) {
            if ($scan->fresh()->isCancelled()) {
                return;
            }

            $rows = [];

            foreach ($batch as $record) {
                $matchedLog = $this->findMatchingLog($record, $logIndex);
                $confidence = null;
                $localPlayer = null;

                if ($matchedLog && $deckVersion) {
                    $cardData = ExtractCardsFromGameLog::run($matchedLog['entries']);
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
                    'game_log_token' => $matchedLog['token'] ?? null,
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

    private function backfillGameLogs(ImportScan $scan): void
    {
        $undecoded = GameLog::whereNull('decoded_entries')->get();

        if ($undecoded->isEmpty()) {
            return;
        }

        $scan->update(['total' => $undecoded->count()]);
        $decoded = 0;

        foreach ($undecoded as $gameLog) {
            if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
                $decoded++;

                continue;
            }

            try {
                $raw = file_get_contents($gameLog->file_path);
                $parsed = ParseGameLogBinary::run($raw);

                if ($parsed && ! empty($parsed['entries'])) {
                    $gameLog->update([
                        'decoded_entries' => $parsed['entries'],
                        'decoded_at' => now(),
                        'byte_offset' => $parsed['byte_offset'],
                        'decoded_version' => ParseGameLogBinary::VERSION,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning("ProcessImportScan: failed to decode {$gameLog->file_path}", [
                    'error' => $e->getMessage(),
                ]);
            }

            $decoded++;

            if ($decoded % 100 === 0) {
                $scan->update(['progress' => $decoded]);
            }
        }

        // Reset progress for the matching phase
        $scan->update(['progress' => 0, 'total' => 0]);
    }

    /**
     * @return array<int, array{token: string, first_timestamp: string, players: string[], entries: array}>
     */
    private function buildGameLogIndex(): array
    {
        // Use subquery to pick the decoded record when duplicates exist
        $gameLogs = GameLog::whereNotNull('decoded_entries')
            ->whereDoesntHave('match')
            ->get()
            ->unique('match_token');

        $index = [];

        foreach ($gameLogs as $gameLog) {
            $entries = $gameLog->decoded_entries;

            if (empty($entries)) {
                continue;
            }

            $players = ExtractGameResults::detectPlayers($entries);

            $index[] = [
                'token' => $gameLog->match_token,
                'first_timestamp' => $entries[0]['timestamp'],
                'players' => $players,
                'entries' => $entries,
            ];
        }

        return $index;
    }

    private function findMatchingLog(array $record, array $logIndex): ?array
    {
        $historyStart = Carbon::parse($record['StartTime']);
        $opponent = $record['Opponents'][0] ?? null;

        if (! $opponent) {
            return null;
        }

        foreach ($logIndex as $log) {
            $logStart = Carbon::parse($log['first_timestamp']);
            $timeDiff = abs($historyStart->diffInSeconds($logStart));

            if ($timeDiff < 300 && in_array($opponent, $log['players'])) {
                return $log;
            }
        }

        return null;
    }
}
