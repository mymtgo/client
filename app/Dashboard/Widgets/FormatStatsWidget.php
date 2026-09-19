<?php

namespace App\Dashboard\Widgets;

use App\Actions\Util\Winrate;
use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetType;
use App\Data\Front\MatchRecordData;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;
use App\Support\MtgoFormat;

class FormatStatsWidget implements WidgetType
{
    public function key(): string
    {
        return 'format_stats';
    }

    public function label(): string
    {
        return 'Format win rates';
    }

    public function span(): int
    {
        return 12;
    }

    public function maxColumns(): int
    {
        return 12;
    }

    public function allowsMultiple(): bool
    {
        return false;
    }

    public function defaultConfig(): array
    {
        return ['formats' => []];
    }

    public function validateConfig(array $config): array
    {
        $formats = $config['formats'] ?? [];

        if (! is_array($formats)) {
            throw new InvalidWidgetConfig('Formats must be a list.');
        }

        $clean = [];
        foreach ($formats as $code) {
            if (! is_string($code)) {
                throw new InvalidWidgetConfig('Format codes must be text.');
            }
            $code = trim($code);
            if ($code !== '' && ! in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        return ['formats' => $clean];
    }

    /** @return array<int, array{code: string, label: string, record: MatchRecordData, gamesWon: int, gamesLost: int, gameWinrate: int}> */
    public function resolve(array $config, DashboardScope $scope): array
    {
        $rows = MtgoMatch::complete()
            ->when($scope->accountId, fn ($q, $id) => $q->forAccount($id))
            ->notLimitedFormat()
            ->when($config['formats'] !== [], fn ($q) => $q->whereIn('matches.format', $config['formats']))
            ->whereBetween('matches.started_at', [$scope->start, $scope->end])
            ->toBase()
            ->leftJoin('games', 'games.match_id', '=', 'matches.id')
            ->groupBy('matches.format')
            ->selectRaw("
                matches.format as format,
                COUNT(DISTINCT matches.id) as total_matches,
                COUNT(DISTINCT CASE WHEN matches.outcome = 'win' THEN matches.id END) as wins,
                COUNT(DISTINCT CASE WHEN matches.outcome = 'loss' THEN matches.id END) as losses,
                SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as games_won,
                SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as games_lost
            ")
            ->get();

        return $rows->map(function ($row) {
            $gamesWon = (int) ($row->games_won ?? 0);
            $gamesLost = (int) ($row->games_lost ?? 0);

            return [
                'code' => (string) $row->format,
                'label' => MtgoFormat::display($row->format),
                'record' => MatchRecord::fromTotal((int) $row->wins, (int) $row->losses, (int) $row->total_matches)->toData(),
                'gamesWon' => $gamesWon,
                'gamesLost' => $gamesLost,
                'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            ];
        })->sortBy('label')->values()->all();
    }
}
