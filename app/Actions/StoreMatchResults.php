<?php

namespace App\Actions;

use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Settings;

class StoreMatchResults
{
    public static function execute(MtgoMatch $match, string $statesJson): MtgoMatch
    {
        $states = json_decode($statesJson, true);

        $lastState = last($states);

        $isComplete = str_contains($lastState, 'MatchClosedState');

        $basePath = Storage::disk('user_home')->path('\\AppData\\Local\\Apps\\2.0\\Data');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
        );

        $gameLog = null;

        foreach ($iterator as $file) {
            if (
                $file->getFilename() == 'Match_GameLog_'.$match->token.'.dat'
            ) {
                if (
                    $file->isFile()
                ) {
                    $gameLog = $file->getPathname();
                    break;
                }
            }
        }

        if (! $gameLog) {
            return $match;
        }

        $logContents = file_get_contents($gameLog);

        preg_match_all(
            '/@P(?<player>[A-Za-z0-9_]+) (wins the game|wins the match)/',
            $logContents,
            $matches,
            PREG_SET_ORDER
        );

        $gameResults = [];
        $lastTerminalPlayer = null;

        foreach ($matches as $event) {
            $isYou = $event['player'] === Settings::get('mtgo_username');

            if (in_array($event[2], [
                'has conceded from the game',
                'has lost connection to the game',
            ])) {
                $lastTerminalPlayer = $event['player'];
            }

            if ($event[2] === 'wins the match') {
                continue;
            }

            $gameResults[] = $isYou;
        }

        if ($isComplete) {
            if (empty($gameResults) && $lastTerminalPlayer) {
                $gameResults[] =
                    $lastTerminalPlayer !== Settings::get('mtgo_username');
            }

            while (
                count($gameResults) < 3 &&
                max(
                    count(array_filter($gameResults)),
                    count($gameResults) - count(array_filter($gameResults))
                ) < 2
            ) {
                $gameResults[] = end($gameResults) ?? false;
            }
        }

        $wins = count(array_filter($gameResults));
        $losses = count($gameResults) - $wins;

        $isComplete = $wins == 2 || $losses == 2;

        foreach ($match->games as $index => $game) {
            if (! array_key_exists($index, $gameResults)) {
                continue;
            }

            $game->update(['won' => $gameResults[$index]]);
        }

        $match->update([
            'status' => $isComplete ? 'completed' : 'in_progress',
            'games_won' => $wins,
            'games_lost' => $losses,
        ]);

        return $match;
    }
}
