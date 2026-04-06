<?php

namespace App\Actions\Dashboard;

use App\Enums\MatchOutcome;
use App\Models\MtgoMatch;

class GetLastSession
{
    private const GAP_HOURS = 2;

    /**
     * @return array{startedAt: string, endedAt: string, matches: array, record: string, duration: string}|null
     */
    public static function run(?int $accountId, ?string $format = null): ?array
    {
        if (! $accountId) {
            return null;
        }

        $matches = MtgoMatch::complete()
            ->forAccount($accountId)
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->with(['opponentArchetypes.archetype', 'games'])
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        $session = collect();
        $session->push($matches->first());

        for ($i = 1; $i < $matches->count(); $i++) {
            $current = $matches[$i];
            $next = $matches[$i - 1];

            $currentEnd = $current->ended_at ?? $current->started_at->copy()->addHour();
            $gap = $currentEnd->diffInMinutes($next->started_at);

            if ($gap > self::GAP_HOURS * 60) {
                break;
            }

            $session->push($current);
        }

        $session = $session->reverse()->values();

        $wins = $session->where('outcome', MatchOutcome::Win)->count();
        $losses = $session->where('outcome', MatchOutcome::Loss)->count();

        $firstMatch = $session->first();
        $lastMatch = $session->last();
        $startedAt = $firstMatch->started_at;
        $endedAt = $lastMatch->ended_at ?? $lastMatch->started_at->copy()->addHour();

        $durationMinutes = $startedAt->diffInMinutes($endedAt);
        $hours = intdiv($durationMinutes, 60);
        $minutes = $durationMinutes % 60;
        $duration = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";

        return [
            'startedAt' => $startedAt->format('M j, Y'),
            'endedAt' => $endedAt->toIso8601String(),
            'matches' => $session->map(fn ($m) => [
                'id' => $m->id,
                'outcome' => $m->outcome->value,
                'opponentArchetype' => $m->opponentArchetypes->first()?->archetype->name ?? 'Unknown',
                'gamesWon' => $m->games->where('won', true)->count(),
                'gamesLost' => $m->games->where('won', false)->count(),
            ])->all(),
            'record' => "{$wins}-{$losses}",
            'duration' => "~{$duration}",
        ];
    }
}
