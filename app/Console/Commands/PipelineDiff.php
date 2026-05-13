<?php

namespace App\Console\Commands;

use App\Actions\Logs\IngestLog;
use App\Actions\Pipeline\ApplyLogEvents;
use App\Models\CardGameStat;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Console\Command;

class PipelineDiff extends Command
{
    protected $signature = 'mtgo:pipeline-diff
        {log : Path to a captured mtgo.log file}
        {--reset : Truncate pipeline tables before running}';

    protected $description = 'Run the new MetaMessage pipeline against a captured mtgo.log and print a row-count summary. Used for stage-2 verification.';

    public function handle(): int
    {
        $logPath = $this->argument('log');

        if (! is_file($logPath)) {
            $this->error("Log file not found: {$logPath}");

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $this->warn('Truncating MtgoMatch, Game, CardGameStat, GameLog, LogEvent...');
            CardGameStat::query()->delete();
            GameLog::query()->delete();
            Game::query()->delete();
            MtgoMatch::query()->delete();
            LogEvent::query()->delete();
        }

        $this->info("Ingesting {$logPath}...");
        $started = microtime(true);
        IngestLog::run($logPath);
        $ingestMs = (int) ((microtime(true) - $started) * 1000);

        $this->info('Applying walker...');
        $started = microtime(true);
        ApplyLogEvents::run();
        $walkMs = (int) ((microtime(true) - $started) * 1000);

        $this->newLine();
        $this->line('===== Pipeline result =====');
        $this->table(
            ['Table', 'Rows'],
            [
                ['log_events', LogEvent::count()],
                ['log_events (unprocessed)', LogEvent::whereNull('processed_at')->count()],
                ['matches', MtgoMatch::count()],
                ['games', Game::count()],
                ['game_logs', GameLog::count()],
                ['card_game_stats', CardGameStat::count()],
            ],
        );
        $this->newLine();
        $this->line("IngestLog: {$ingestMs} ms");
        $this->line("ApplyLogEvents: {$walkMs} ms");

        return self::SUCCESS;
    }
}
