<?php

namespace Tests\Helpers;

use App\Models\GameEvent;
use App\Models\MtgoMatch;

class SidecarSnapshotFactory
{
    /**
     * @param  array<string, mixed>|null  $league
     * @param  array<string, mixed>|null  $deck
     */
    public static function snapshot(MtgoMatch $match, string $phase = 'started', ?array $league = null, ?array $deck = null, bool $verified = true): GameEvent
    {
        $data = ['phase' => $phase, 'registered_deck' => $deck];

        if ($league !== null) {
            $data['league'] = $league;
        }

        return GameEvent::factory()->create([
            'type' => 'match_snapshot',
            'game_mtgo_id' => null,
            'match_mtgo_id' => (string) $match->mtgo_id,
            'verified' => $verified,
            'data' => $data,
        ]);
    }

    /** A match_started event, so ApplySidecarProjection has a fold view for the match. */
    public static function matchStarted(MtgoMatch $match): GameEvent
    {
        return GameEvent::factory()->create([
            'type' => 'match_started',
            'game_mtgo_id' => null,
            'match_mtgo_id' => (string) $match->mtgo_id,
            'data' => ['players' => [], 'format' => 'CMODERN', 'event_type' => 'league', 'event_name' => null],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function league(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 10983,
            'token' => 'league-token-123',
            'name' => 'Modern League',
            'total_matches' => 5,
            'min_matches' => 1,
            'wins' => 0,
            'losses' => 0,
            'match_number' => 1,
            'matches_remaining' => 5,
            'active_deck_net_deck_id' => 12345,
            'game_history' => [],
            'has_joined' => true,
            'is_participant' => true,
            'is_match_in_progress' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function deck(array $overrides = []): array
    {
        return array_merge([
            'net_deck_id' => 12345,
            'hash' => 'h',
            'name' => 'Test Deck',
            'timestamp' => '2026-09-24T18:10:02.000Z',
            'main_count' => 60,
            'side_count' => 15,
            'items' => [
                ['catalog_id' => 1001, 'quantity' => 4, 'sideboard' => false],
                ['catalog_id' => 1003, 'quantity' => 2, 'sideboard' => true],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $league
     */
    public static function leagueLeft(array $league, bool $verified = true): GameEvent
    {
        return GameEvent::factory()->create([
            'type' => 'league_left',
            'game_mtgo_id' => null,
            'match_mtgo_id' => null,
            'verified' => $verified,
            'data' => ['league' => $league],
        ]);
    }

    /** Writes a healthy status.json so AwaitSidecarAnswer sees an attached sidecar. */
    public static function attachedStatus(string $dir): void
    {
        file_put_contents($dir.'/status.json', json_encode([
            'state' => 'attached',
            'heartbeat' => now()->toIso8601ZuluString(),
        ]));
    }
}
