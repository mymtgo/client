<?php

namespace App\Console\Commands;

use App\Actions\Logs\ClassifyLogEvent;
use App\Actions\Tournaments\ProcessTournamentEvents;
use App\Models\LogEvent;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use App\Models\TournamentTimelineEvent;
use Illuminate\Console\Command;

class SeedTournamentsFromLog extends Command
{
    protected $signature = 'tournaments:seed-from-log {path=storage/app/mtgo.log}';

    protected $description = 'Seed tournament data from an MTGO log file through the real pipeline';

    public function handle(): int
    {
        $path = $this->argument('path');
        $fullPath = base_path($path);

        if (! file_exists($fullPath)) {
            $this->error("Log file not found: {$fullPath}");

            return self::FAILURE;
        }

        $this->info("Reading log file: {$fullPath}");

        $tournamentEventTypes = [
            'tournament_sync',
            'tournament_state_changed',
            'tournament_round_result',
            'tournament_player_eliminated',
            'tournament_ended',
            'tournament_match_state_changed',
        ];

        $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $created = 0;

        // Group multi-line log entries (lines starting with timestamp are new entries)
        $entries = [];
        $currentEntry = '';
        foreach ($lines as $line) {
            if (preg_match('/^\d{2}:\d{2}:\d{2}\s+\[/', $line)) {
                if ($currentEntry !== '') {
                    $entries[] = $currentEntry;
                }
                $currentEntry = $line;
            } else {
                $currentEntry .= "\n".$line;
            }
        }
        if ($currentEntry !== '') {
            $entries[] = $currentEntry;
        }

        $this->info('Parsed '.count($entries).' log entries');

        $bar = $this->output->createProgressBar(count($entries));

        foreach ($entries as $index => $entry) {
            $event = new LogEvent(['raw_text' => $entry]);
            $classified = ClassifyLogEvent::run($event);

            if ($classified->event_type && in_array($classified->event_type, $tournamentEventTypes)) {
                $loggedAt = null;
                if (preg_match('/^(\d{2}:\d{2}:\d{2})/', $entry, $m)) {
                    $loggedAt = '2026-03-18 '.$m[1];
                }

                LogEvent::create([
                    'raw_text' => $entry,
                    'event_type' => $classified->event_type,
                    'match_token' => $classified->match_token,
                    'match_id' => $classified->match_id,
                    'game_id' => $classified->game_id,
                    'timestamp' => $m[1] ?? null,
                    'logged_at' => $loggedAt,
                    'file_path' => 'seed',
                    'byte_offset_start' => $index,
                    'byte_offset_end' => $index,
                    'level' => 'INF',
                    'category' => 'seed',
                    'context' => 'seed',
                    'ingested_at' => now(),
                ]);
                $created++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$created} tournament log events");

        $this->info('Processing tournament events...');
        ProcessTournamentEvents::run();

        $tournamentCount = Tournament::count();
        $standingCount = TournamentStanding::count();
        $timelineCount = TournamentTimelineEvent::count();
        $playerCount = Player::whereNotNull('login_id')->count();

        $this->info("Done! Created {$tournamentCount} tournaments, {$standingCount} standings, {$timelineCount} timeline events, {$playerCount} player mappings");

        return self::SUCCESS;
    }
}
