<?php

namespace App\Support;

use App\Actions\Util\Winrate;
use App\Data\Front\MatchRecordData;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Wins, losses and draws for a set of matches, plus everything derived
 * from them. Draws count as matches played, so the percentage always
 * agrees with the record shown beside it. This is the only place a match
 * win rate or a W-L-D label is computed.
 */
final readonly class MatchRecord
{
    private function __construct(
        public int $wins,
        public int $losses,
        public int $draws,
    ) {}

    public static function fromCounts(int $wins, int $losses, int $draws = 0): self
    {
        return new self(max(0, $wins), max(0, $losses), max(0, $draws));
    }

    /**
     * Build from a total when only decisive outcomes were counted; anything
     * that is neither a win nor a loss is treated as a draw.
     */
    public static function fromTotal(int $wins, int $losses, int $total): self
    {
        return self::fromCounts($wins, $losses, $total - $wins - $losses);
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * Aggregate a query whose base table is `matches`. Joins are allowed
     * only if they do not multiply match rows. Selects, ordering and limits
     * on the given query are ignored.
     */
    public static function fromQuery(EloquentBuilder|QueryBuilder $query): self
    {
        $base = clone ($query instanceof EloquentBuilder ? $query->toBase() : $query);
        $base->columns = null;
        $base->limit = null;
        $base->offset = null;

        $row = $base
            ->reorder()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN matches.outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN matches.outcome = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->first();

        return self::fromTotal((int) ($row->wins ?? 0), (int) ($row->losses ?? 0), (int) ($row->total ?? 0));
    }

    public function total(): int
    {
        return $this->wins + $this->losses + $this->draws;
    }

    public function winrate(): int
    {
        return Winrate::percentage($this->wins, $this->losses, $this->draws);
    }

    public function label(): string
    {
        $label = $this->wins.' - '.$this->losses;

        return $this->draws > 0 ? $label.' - '.$this->draws : $label;
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    public function add(self $other): self
    {
        return new self(
            $this->wins + $other->wins,
            $this->losses + $other->losses,
            $this->draws + $other->draws,
        );
    }

    public function toData(): MatchRecordData
    {
        return new MatchRecordData(
            wins: $this->wins,
            losses: $this->losses,
            draws: $this->draws,
            total: $this->total(),
            winrate: $this->winrate(),
            label: $this->label(),
        );
    }
}
