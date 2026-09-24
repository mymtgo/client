<?php

namespace App\Actions\Sidecar;

use App\Models\GameEvent;
use App\Sidecar\SidecarGameView;
use App\Sidecar\SidecarMatchView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSidecarMatchView
{
    /**
     * The only event types the fold reads. Everything else the sidecar
     * captures (card_tapped, card_zone_changed, mana_pool_changed,
     * life_changed, ...) is replay detail, read straight from `game_events`
     * by the replay view and never folded. A real match is thousands of
     * those rows and this runs on every pipeline tick for the live match,
     * so hydrating them (a JSON decode of `data` and `ref` each) would be
     * the dominant cost of the tick.
     *
     * @var list<string>
     */
    public const FOLD_TYPES = [
        'match_started',
        'match_ended',
        'sideboarding_started',
        'sideboard_submitted',
        'game_started',
        'game_ended',
        'turn_started',
        'clock_tick',
        'keyframe',
    ];

    public static function run(string $matchMtgoId): ?SidecarMatchView
    {
        $events = GameEvent::query()
            ->where('match_mtgo_id', $matchMtgoId)
            ->whereIn('type', self::FOLD_TYPES)
            ->orderBy('session_started_at')
            ->orderBy('seq')
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        // Verification is a property of the subject, not of the types the
        // fold happens to read: the spec's gate is "every event for the
        // subject has verified = true". One grouped aggregate over ALL of
        // the match's events answers it for the match and for each game
        // without hydrating the excluded rows.
        $unverifiedCounts = GameEvent::query()
            ->where('match_mtgo_id', $matchMtgoId)
            ->where('verified', false)
            ->selectRaw('game_mtgo_id, count(*) as n')
            ->groupBy('game_mtgo_id')
            ->pluck('n', 'game_mtgo_id');

        $matchAllVerified = $unverifiedCounts->isEmpty();

        $matchPlayers = [];
        $matchResult = null;
        $matchEnded = false;

        foreach ($events->whereNull('game_mtgo_id') as $e) {
            if ($e->type === 'match_started') {
                foreach ($e->data['players'] ?? [] as $p) {
                    $matchPlayers[(int) $p['p']] = (string) $p['name'];
                }
            }
            if ($e->type === 'match_ended') {
                $matchEnded = true;
                $winnerSlot = $e->data['winner_p'] ?? null;
                $matchResult = [
                    'winner' => $winnerSlot === null ? null : ($matchPlayers[(int) $winnerSlot] ?? null),
                    'score' => [(int) ($e->data['score'][0] ?? 0), (int) ($e->data['score'][1] ?? 0)],
                ];
            }
        }

        $sideboardUsed = self::sideboardTimes($events);

        $games = [];
        foreach ($events->whereNotNull('game_mtgo_id')->groupBy('game_mtgo_id') as $gameId => $gameEvents) {
            $firstTs = $gameEvents->first()->ts;
            $window = collect($sideboardUsed)->last(fn ($w) => $w['started'] < $firstTs);
            $games[(string) $gameId] = self::foldGame(
                (string) $gameId,
                $gameEvents,
                $matchPlayers,
                $window['used'] ?? [],
                ((int) ($unverifiedCounts[(string) $gameId] ?? 0)) === 0,
            );
        }

        if ($matchPlayers === [] && $games !== []) {
            $matchPlayers = reset($games)->playerNames;
        }

        $probeUsername = self::latestProbeUsername($events->first()->session_started_at);

        return new SidecarMatchView(
            matchMtgoId: $matchMtgoId,
            playerNames: $matchPlayers,
            matchResult: $matchResult,
            matchEnded: $matchEnded,
            allVerified: $matchAllVerified,
            latestProbeUsername: $probeUsername,
            games: $games,
        );
    }

    /**
     * Sideboarding windows in fold order: each with the `sideboarding_started`
     * ts and, per slot, the ms until that slot's `sideboard_submitted`.
     *
     * @param  Collection<int, GameEvent>  $events
     * @return list<array{started: CarbonImmutable, used: array<int, int>}>
     */
    private static function sideboardTimes(Collection $events): array
    {
        $windows = [];
        foreach ($events->whereNull('game_mtgo_id') as $e) {
            if ($e->type === 'sideboarding_started') {
                $windows[] = ['started' => $e->ts, 'used' => []];
            } elseif ($e->type === 'sideboard_submitted' && $windows !== []) {
                $i = array_key_last($windows);
                $windows[$i]['used'][(int) $e->data['p']] = (int) $windows[$i]['started']->diffInMilliseconds($e->ts, absolute: true);
            }
        }

        return $windows;
    }

    /**
     * @param  Collection<int, GameEvent>  $events  already in fold order
     * @param  array<int, string>  $fallbackNames
     * @param  array<int, int>  $sideboardUsed  slot => ms, for the window before this game
     * @param  bool  $allVerified  computed over every event of the game, not just the folded types
     */
    private static function foldGame(string $gameId, Collection $events, array $fallbackNames, array $sideboardUsed, bool $allVerified): SidecarGameView
    {
        $names = $fallbackNames;
        $gameNumber = 0;
        $startedAt = null;
        $endedAt = null;
        $winnerSlot = null;
        $onPlaySlot = null;
        $hasStart = false;
        $hasEnd = false;
        $turnCount = 0;
        $clock = [];
        $keyframes = [];

        foreach ($events as $e) {
            switch ($e->type) {
                case 'game_started':
                    $hasStart = true;
                    $startedAt = $e->ts;
                    $gameNumber = (int) ($e->data['game_number'] ?? 0);
                    foreach ($e->data['players'] ?? [] as $p) {
                        $names[(int) $p['p']] = (string) $p['name'];
                        if (! empty($p['on_play'])) {
                            $onPlaySlot = (int) $p['p'];
                        }
                    }
                    break;
                case 'game_ended':
                    $hasEnd = true;
                    $endedAt = $e->ts;
                    $winnerSlot = isset($e->data['winner_p']) ? (int) $e->data['winner_p'] : null;
                    break;
                case 'turn_started':
                    $turnCount = max($turnCount, (int) ($e->data['turn'] ?? 0));
                    break;
                case 'clock_tick':
                    $slot = (int) $e->data['p'];
                    $ms = (int) $e->data['remaining_ms'];
                    $clock[$slot]['end'] = $ms;
                    $clock[$slot]['min'] = min($clock[$slot]['min'] ?? $ms, $ms);
                    break;
                case 'keyframe':
                    if (($e->data['trigger'] ?? null) === 'game_start') {
                        foreach ($e->data['players'] ?? [] as $p) {
                            if (isset($p['p'], $p['clock_ms'])) {
                                $clock[(int) $p['p']]['start'] = (int) $p['clock_ms'];
                            }
                        }
                    }
                    $keyframes[] = ['ts' => $e->ts->toIso8601ZuluString('millisecond')] + $e->data;
                    break;
            }
        }

        $clockByName = [];
        foreach ($clock as $slot => $summary) {
            if (isset($names[$slot])) {
                $clockByName[$names[$slot]] = [
                    'start' => $summary['start'] ?? null,
                    'end' => $summary['end'] ?? null,
                    'min' => $summary['min'] ?? null,
                    'sideboard_used' => $sideboardUsed[$slot] ?? null,
                ];
            }
        }

        return new SidecarGameView(
            gameMtgoId: $gameId,
            gameNumber: $gameNumber,
            playerNames: $names,
            startedAt: $startedAt instanceof CarbonImmutable ? $startedAt : null,
            endedAt: $hasEnd && $endedAt instanceof CarbonImmutable ? $endedAt : null,
            winnerName: $hasEnd && $winnerSlot !== null ? ($names[$winnerSlot] ?? null) : null,
            onPlayName: $onPlaySlot !== null ? ($names[$onPlaySlot] ?? null) : null,
            hasStart: $hasStart,
            hasEnd: $hasEnd,
            allVerified: $allVerified,
            turnCount: $turnCount,
            clockByName: $clockByName,
            keyframes: $keyframes,
        );
    }

    private static function latestProbeUsername(CarbonImmutable $notBefore): ?string
    {
        $probe = GameEvent::query()
            ->where('type', 'probe')
            ->where('verified', true)
            ->where('session_started_at', '<=', $notBefore->addDay())
            ->orderByDesc('session_started_at')
            ->orderByDesc('seq')
            ->first();

        return $probe?->data['username'] ?? null;
    }
}
