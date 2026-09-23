<?php

use App\Actions\Sidecar\ParseSidecarLine;

it('ships a scrubbed full game fixture with no real usernames', function () {
    $path = base_path('tests/fixtures/sidecar/events-game-full.ndjson');
    expect(file_exists($path))->toBeTrue();

    $lines = array_values(array_filter(explode("\n", file_get_contents($path))));
    $events = array_map(fn (string $l) => ParseSidecarLine::run($l), $lines);
    $types = array_count_values(array_map(fn ($e) => $e->type, $events));

    expect($types['session_started'] ?? 0)->toBe(1)
        ->and($types['match_started'] ?? 0)->toBe(1)
        ->and($types['game_started'] ?? 0)->toBe(1)
        ->and($types['game_ended'] ?? 0)->toBe(1)
        ->and($types['keyframe'] ?? 0)->toBeGreaterThanOrEqual(3)
        ->and($types['card_zone_changed'] ?? 0)->toBeGreaterThan(100)
        ->and(count($lines))->toBeGreaterThan(500);

    $names = [];
    foreach ($events as $e) {
        foreach ($e->data['players'] ?? [] as $p) {
            if (isset($p['name'])) {
                $names[$p['name']] = true;
            }
        }
        if (isset($e->data['username'])) {
            $names[$e->data['username']] = true;
        }
    }
    ksort($names);
    expect(array_keys($names))->toBe(['Opp_Name', 'local.player']);

    $matchStarted = collect($events)->firstWhere('type', 'match_started');
    expect($matchStarted->data['event_type'])->toBe('casual')
        ->and($matchStarted->data)->toHaveKey('event_name');

    // one game only
    $gameIds = collect($events)->pluck('game')->filter()->unique();
    expect($gameIds)->toHaveCount(1);
});
