<?php

use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);
});

/**
 * A match whose opponent plays a deck the local corpus can classify. The
 * only difference between the two tests below is the format code.
 */
function detectableMatch(string $format): MtgoMatch
{
    $archetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $card = Card::factory()->create(['mtgo_id' => 99001, 'oracle_id' => 'oracle-test-card-001']);
    $deck = ArchetypeDeck::factory()->for($archetype)->create();
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::factory()->create([
        'format' => $format,
        'archetype_detection_queued_at' => now(),
    ]);
    $game = Game::factory()->create(['match_id' => $match->id]);

    $game->players()->attach(Player::factory()->create()->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [['mtgo_id' => 99001, 'quantity' => 4]],
    ]);

    $game->players()->attach(Player::factory()->create()->id, [
        'instance_id' => 2,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [],
    ]);

    return $match;
}

it('skips detection for a limited match', function () {
    $match = detectableMatch('DHOBHOBHOB');

    (new DetermineMatchArchetypesJob($match->id))->handle();

    expect(DB::table('match_archetypes')->where('mtgo_match_id', $match->id)->count())->toBe(0)
        ->and($match->fresh()->archetype_detection_queued_at)->toBeNull();
});

it('still detects for a constructed match', function () {
    $match = detectableMatch('CMODERN');

    (new DetermineMatchArchetypesJob($match->id))->handle();

    expect(DB::table('match_archetypes')->where('mtgo_match_id', $match->id)->count())->toBeGreaterThan(0);
});
