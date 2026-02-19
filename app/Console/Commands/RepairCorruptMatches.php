<?php

namespace App\Console\Commands;

use App\Actions\Matches\GetGameLog;
use App\Facades\Mtgo;
use App\Models\MtgoMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairCorruptMatches extends Command
{
    protected $signature = 'matches:repair-corrupt {--dry-run : Show what would change without writing to DB}';

    protected $description = 'Fix matches where games_won + games_lost < 2 or equals 1-1 due to mid-match quits/concedes';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        // Derive local player username from the game_player table (most frequent is_local player).
        // This mirrors what the NativePHP Settings facade provides during live ingestion.
        $localUsername = DB::table('game_player as gp')
            ->join('players as p', 'p.id', '=', 'gp.player_id')
            ->where('gp.is_local', true)
            ->select('p.username', DB::raw('COUNT(*) as appearances'))
            ->groupBy('p.username')
            ->orderByDesc('appearances')
            ->value('username');

        if (! $localUsername) {
            $this->error('Cannot determine local player username from game_player table.');

            return self::FAILURE;
        }

        Mtgo::setUsername($localUsername);
        $this->line("Local player: <info>{$localUsername}</info>");

        $matches = MtgoMatch::whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereRaw('(games_won + games_lost) < 2')
                    ->orWhereRaw('(games_won = 1 AND games_lost = 1)');
            })
            ->get();

        if ($matches->isEmpty()) {
            $this->info('No corrupt matches found.');

            return self::SUCCESS;
        }

        $this->info("Found {$matches->count()} corrupt match(es).");

        $headers = ['ID', 'Token', 'Current', 'Local Conceded?', 'Log W', 'Log L', 'Fixed'];
        $rows = [];
        $fixed = 0;
        $skipped = 0;

        foreach ($matches as $match) {
            $stateChanges = DB::table('log_events')
                ->where('match_token', $match->token)
                ->where('event_type', 'match_state_changed')
                ->pluck('context');

            $localConceded = $stateChanges->contains(
                fn ($c) => str_contains($c ?? '', 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState')
            );

            $gameLog = GetGameLog::run($match->token);

            if ($gameLog === null) {
                $rows[] = [$match->id, substr($match->token, 0, 8), "{$match->games_won}-{$match->games_lost}", '—', '—', '—', 'SKIP (no log)'];
                $skipped++;

                continue;
            }

            $logResults = $gameLog['results'] ?? [];
            $logWins = count(array_filter($logResults, fn ($r) => $r === true));
            $logLosses = count(array_filter($logResults, fn ($r) => $r === false));

            if ($localConceded) {
                $newWins = $logWins;
                $newLosses = 2;
            } else {
                $newWins = 2;
                $newLosses = $logLosses;
            }

            $rows[] = [
                $match->id,
                substr($match->token, 0, 8),
                "{$match->games_won}-{$match->games_lost}",
                $localConceded ? 'Yes' : 'No',
                $logWins,
                $logLosses,
                "{$newWins}-{$newLosses}",
            ];

            if (! $dry) {
                $match->update([
                    'games_won' => $newWins,
                    'games_lost' => $newLosses,
                ]);
            }

            $fixed++;
        }

        $this->table($headers, $rows);

        if ($dry) {
            $this->warn('Dry run — no changes written. Re-run without --dry-run to apply.');
        } else {
            $this->info("Repaired {$fixed} match(es). Skipped {$skipped}.");
        }

        return self::SUCCESS;
    }
}
