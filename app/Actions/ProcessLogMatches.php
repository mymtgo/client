<?php

namespace App\Actions;

use App\Data\GameCardData;
use App\Data\GameData;
use App\Data\GameEntryData;
use App\Data\GamePlayerData;
use App\Data\MatchData;
use App\Jobs\ProcessMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Native\Laravel\Facades\Settings;

class ProcessLogMatches
{
    public string $usernameCheckString = '';

    public Carbon $date;

    public array $entries = [];

    public function __construct(string $logPath)
    {
        $log = Storage::json($logPath);

        $this->date = now()->parse($log['date']);
        $this->entries = $log['entries'];
    }

    public function run(): void
    {
        $matches = $this->getMatches();

        foreach ($matches as $match) {
            $states = $this->getMatchStates($match);
            $matchData = $this->getMatchGames($match);
            ProcessMatch::dispatch($matchData->toJson(), $states->toJson());
        }
    }

    protected function getMatchStates(MatchData $match): Collection
    {
        $states = collect([]);

        foreach ($this->getEntries() as $entry) {
            if (str_contains($entry['content'], 'Match State Changed for '.$match->matchToken)) {
                $states->push($entry['content']);
            }
        }

        return $states;
    }

    protected function getEntries(): array
    {
        return $this->entries;
    }

    protected function getMatchGames(MatchData $match): MatchData
    {
        foreach ($this->getEntries() as $entry) {
            if (! $this->isGameStateLine($entry['content'])) {
                continue;
            }

            $ids = $this->extractGameAndMatchIds($entry['content']);

            if ((string) $ids['match_id'] !== (string) $match->matchId) {
                continue;
            }

            $state = $this->extractGameStateJson($entry['content']);

            // Do we already have a game for this match set?
            $firstInstance = false;
            $matchGame = $match->games->first(
                fn ($game) => $game->gameId == $ids['game_id']
            );

            if (! $matchGame) {
                $firstInstance = true;
                $matchGame = GameData::from([
                    'gameId' => $ids['game_id'],
                ]);
            }

            $timeSegs = explode(':', $entry['timestamp']);

            $matchGame->entries->push(
                GameEntryData::from([
                    'timestamp' => $this->date->clone()->setTime($timeSegs[0], $timeSegs[1], $timeSegs[2]),
                    'players' => GamePlayerData::collect(
                        $state['Players']
                    ),
                    'cards' => GameCardData::collect(
                        $state['Cards']
                    ),
                ])
            );

            if ($firstInstance) {
                $match->games->push($matchGame);
            }
        }

        foreach ($this->getEntries() as $entry) {
            if (! str_contains($entry['content'], 'Deck Used in Game ID:')) {
                continue;
            }

            if (! preg_match('/Game ID:\s*(\d+)/', $entry['content'], $m)) {
                continue;
            }

            $gameId = (int) $m[1];

            $game = $match->games->first(
                fn ($g) => $g->gameId == $gameId
            );

            if (! $game) {
                continue;
            }

            if (! preg_match('/(\[\{.*\}\])$/s', $entry['content'], $json)) {
                continue;
            }

            $deck = json_decode($json[1], true);

            $game->deck = collect($deck);
        }

        return $match;
    }

    protected function isGameStateLine(string $content): bool
    {
        return str_contains(
            $content,
            'Game Play Status Update for Game ID:'
        );
    }

    protected function isDeckUsedLine(string $content): bool
    {
        return str_contains(
            $content,
            'Deck Used in Game ID:'
        );
    }

    protected function extractDeckJson(string $content): ?array
    {
        if (! preg_match('/(\[\{.*\}\])$/s', $content, $m)) {
            return null;
        }

        return json_decode($m[1], true);
    }

    protected function extractGameIdFromDeckLine(string $content): ?int
    {
        if (! preg_match('/Game ID:\s*(\d+)/', $content, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    protected function extractGameAndMatchIds(string $content): ?array
    {
        if (! preg_match(
            '/Game ID:\s*(\d+),\s*Match ID:\s*(\d+)/',
            $content,
            $m
        )) {
            return null;
        }

        return [
            'game_id' => (int) $m[1],
            'match_id' => (int) $m[2],
        ];
    }

    protected function getMatches(): Collection
    {
        $matches = [];

        foreach ($this->getEntries() as $entry) {

            if (! $json = $this->extractJsonFromMessage($entry['content'])) {
                continue;
            }

            if (empty($json['MatchID'])) {
                continue;
            }

            $keyValues = $this->extractKeyValueBlock($entry['content']);

            $matches[$json['MatchID']] = [
                'MatchID' => $json['MatchID'],
                'MatchToken' => $json['MatchToken'],
                ...$keyValues,
            ];
        }

        return collect($matches)->map(
            fn ($match) => MatchData::from([
                'date' => $this->date,
                ...$match,
            ])
        )->values();
    }

    protected function extractKeyValueBlock(string $message): array
    {
        $data = [];

        // Everything after "Receiver:" is metadata
        if (! str_contains($message, 'Receiver:')) {
            return $data;
        }

        $tail = substr($message, strpos($message, 'Receiver:'));

        foreach (preg_split('/\R/', $tail) as $line) {
            if (preg_match('/^([A-Za-z0-9]+)\s*=\s*(.+)$/', trim($line), $m)) {
                $data[$m[1]] = trim($m[2]);
            }
        }

        return $data;
    }

    protected function extractGameStateJson(string $content): ?array
    {
        if (! preg_match('/\)\s*(\{.*})$/s', $content, $m)) {
            return null;
        }

        return json_decode($m[1], true);
    }

    protected function extractJsonFromMessage(string $message)
    {
        $jsonMatch = preg_match('/ Message:\s*(\{.*?\})/s', $message, $json);

        if (! $jsonMatch) {
            return null;
        }

        return json_decode($json[1], true);
    }

    protected function getUsername(): string
    {
        $username = Settings::get('mtgo_username');

        if (! $username) {
            foreach ($this->getEntries() as $entry) {
                preg_match(
                    '/MtGO Login Last Success\)\s+Username:\s*(\S+)/',
                    $entry['content'],
                    $matches
                );

                if (count($matches)) {
                    $username = trim($matches[1]);

                    Settings::set('mtgo_username', $username);
                }
            }
        }

        return $username;
    }
}
