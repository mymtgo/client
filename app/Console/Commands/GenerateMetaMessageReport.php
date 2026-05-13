<?php

namespace App\Console\Commands;

use App\Actions\Logs\ParseMetaMessage;
use Illuminate\Console\Command;

class GenerateMetaMessageReport extends Command
{
    protected $signature = 'mtgo:meta-report {log : Path to an mtgo.log file} {--out= : Markdown output path}';

    protected $description = 'Read mtgo.log MetaMessage entries and emit a per-match markdown summary.';

    public function handle(): int
    {
        $logPath = $this->argument('log');

        if (! is_file($logPath)) {
            $this->error("Log file not found: {$logPath}");

            return self::FAILURE;
        }

        $outPath = $this->option('out') ?? dirname($logPath).'/meta-report.md';

        $matches = $this->aggregate($logPath);
        $markdown = $this->renderMarkdown($matches);

        file_put_contents($outPath, $markdown);

        $this->info("Wrote report to {$outPath}");
        $this->line('Matches: '.count($matches));

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function aggregate(string $logPath): array
    {
        $matches = [];
        $fh = fopen($logPath, 'rb');

        if ($fh === false) {
            return [];
        }

        while (($line = fgets($fh)) !== false) {
            if (! str_contains($line, '"MetaMessage":[')) {
                continue;
            }

            if (! preg_match('/"MatchID":(?<match>\d+),"GameID":(?<game>\d+),"MetaMessage":\[(?<bytes>[^\]]+)\]/', $line, $m)) {
                continue;
            }

            $matchId = $m['match'];
            $gameId = (int) $m['game'];

            if ($matchId === '0') {
                continue;
            }

            $bytes = array_map('intval', explode(',', $m['bytes']));
            $parsed = ParseMetaMessage::run($bytes);

            if ($parsed === null) {
                continue;
            }

            $matches[$matchId] ??= [
                'match_id' => $matchId,
                'players' => [],
                'games' => [],
            ];

            $game = &$matches[$matchId]['games'][$gameId];
            $game ??= [
                'game_id' => $gameId,
                'index' => count($matches[$matchId]['games']) + 1,
                'deck' => null,
                'die_rolls' => [],
                'play_choice' => null,
                'mulligans' => [],
                'starting_hands' => [],
                'winner' => null,
                'concede' => null,
                'turns' => 0,
                'players' => [],
            ];

            $this->applyEvent($parsed, $matches[$matchId], $game);
            unset($game);
        }

        fclose($fh);

        foreach ($matches as &$match) {
            ksort($match['games']);
            $match['games'] = array_values($match['games']);
        }

        return $matches;
    }

    private function applyEvent(array $parsed, array &$match, array &$game): void
    {
        $event = $parsed['event'];

        if ($parsed['kind'] === 'deck_list' && $game['deck'] === null && $parsed['cards']) {
            $game['deck'] = $parsed['cards'];
        }

        if (! $event) {
            return;
        }

        $player = $event['player'] ?? null;

        if ($player !== null) {
            $game['players'][$player] = true;
            $match['players'][$player] = true;
        }

        switch ($event['action']) {
            case 'die_roll':
                $game['die_rolls'][$player] = $event['value'];
                break;

            case 'play_choice':
                $game['play_choice'] ??= ['player' => $player, 'choice' => $event['value']];
                break;

            case 'mulligan':
                $game['mulligans'][$player] = $event['value'];
                break;

            case 'starting_hand':
                $game['starting_hands'][$player] = $event['value'];
                break;

            case 'game_winner':
                $game['winner'] ??= $player;
                break;

            case 'concede':
                $game['concede'] ??= $player;
                break;

            case 'turn_start':
                $game['turns'] = max($game['turns'], $event['value']);
                break;
        }
    }

    private function renderMarkdown(array $matches): string
    {
        $lines = [];
        $lines[] = '# MTGO MetaMessage Report';
        $lines[] = '';
        $lines[] = 'Generated '.now()->toDateTimeString();
        $lines[] = '';
        $lines[] = 'Total matches with MetaMessage data: '.count($matches);
        $lines[] = '';

        foreach ($matches as $match) {
            $lines = array_merge($lines, $this->renderMatch($match));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<int, string>
     */
    private function renderMatch(array $match): array
    {
        $games = $match['games'];
        $gameCount = count($games);

        $winCounts = [];
        foreach ($games as $g) {
            if ($g['winner']) {
                $winCounts[$g['winner']] = ($winCounts[$g['winner']] ?? 0) + 1;
            }
        }

        $matchWinner = null;
        if ($winCounts) {
            arsort($winCounts);
            $matchWinner = array_key_first($winCounts);
        }

        $lines = [];
        $lines[] = "## Match {$match['match_id']}";
        $lines[] = '';
        $lines[] = '- Players: '.(implode(' vs ', array_keys($match['players'])) ?: 'unknown');
        $lines[] = '- Games played: '.$gameCount;
        $score = collect($winCounts)->map(fn ($n, $p) => "{$p}: {$n}")->implode(', ');
        $lines[] = '- Match score: '.($score ?: 'unknown');
        $lines[] = '- Match winner: '.($matchWinner ?? 'unknown');
        $lines[] = '';

        foreach ($games as $idx => $game) {
            $gameNum = $idx + 1;
            $lines[] = "### Game {$gameNum} (GameID {$game['game_id']})";
            $lines[] = '';
            $lines[] = '- Turns played: '.($game['turns'] ?: 'n/a');
            $lines[] = '- On the play: '.$this->describeFirstPlayer($game);
            $lines[] = '- Die rolls: '.$this->describeDieRolls($game);
            $lines[] = '- Mulligans: '.$this->describeMulligans($game);
            $lines[] = '- Winner: '.($game['winner'] ?? 'unknown')
                .($game['concede'] ? " (via {$game['concede']} conceding)" : '');

            if ($idx > 0 && $games[$idx - 1]['deck'] && $game['deck']) {
                $lines[] = '- Sideboard delta vs G'.$idx.': '.$this->describeSideboardDelta($games[$idx - 1]['deck'], $game['deck']);
            }

            $lines[] = '';
        }

        $lines[] = '> Deck ID: not present in MetaMessage stream (deck identified by card composition only).';
        $lines[] = '';

        return $lines;
    }

    private function describeFirstPlayer(array $game): string
    {
        if ($game['play_choice']) {
            return "{$game['play_choice']['player']} ({$game['play_choice']['choice']})";
        }

        return 'unknown';
    }

    private function describeDieRolls(array $game): string
    {
        if (! $game['die_rolls']) {
            return 'not logged (game 2+)';
        }

        return collect($game['die_rolls'])
            ->map(fn ($v, $p) => "{$p}={$v}")
            ->implode(', ');
    }

    private function describeMulligans(array $game): string
    {
        if (! $game['mulligans']) {
            return 'none';
        }

        return collect($game['mulligans'])
            ->map(fn ($v, $p) => "{$p} to {$v}")
            ->implode(', ');
    }

    /**
     * @param  array<int, int>  $prev
     * @param  array<int, int>  $curr
     */
    private function describeSideboardDelta(array $prev, array $curr): string
    {
        $prevCounts = array_count_values($prev);
        $currCounts = array_count_values($curr);

        $added = [];
        $removed = [];

        foreach ($currCounts as $id => $n) {
            $diff = $n - ($prevCounts[$id] ?? 0);
            if ($diff > 0) {
                $added[] = "+{$diff} #{$id}";
            }
        }

        foreach ($prevCounts as $id => $n) {
            $diff = $n - ($currCounts[$id] ?? 0);
            if ($diff > 0) {
                $removed[] = "-{$diff} #{$id}";
            }
        }

        if (! $added && ! $removed) {
            return 'no changes';
        }

        return implode(', ', array_merge($added, $removed));
    }
}
