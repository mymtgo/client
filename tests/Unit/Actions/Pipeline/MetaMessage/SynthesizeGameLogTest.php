<?php

use App\Actions\Pipeline\MetaMessage\SynthesizeGameLog;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * Build a raw_text payload wrapping a valid MetaMessage byte array
 * carrying the given chat message.
 */
function metaMessageRawText(string $message): string
{
    $len = strlen($message);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, $len, 0, 0, 0],
        array_map('ord', str_split($message)),
    );

    return 'Message: {"MetaMessage":['.implode(',', $bytes).']} trailing';
}

it('synthesises a GameLog with decoded_entries from parseable LogEvents', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 555]);

    LogEvent::factory()->create([
        'game_id' => 555,
        'event_type' => 'game_management_json',
        'raw_text' => metaMessageRawText('@Palice rolled a 1.'),
        'logged_at' => now()->setMicroseconds(0),
    ]);
    LogEvent::factory()->create([
        'game_id' => 555,
        'event_type' => 'game_management_json',
        'raw_text' => metaMessageRawText('@Pbob rolled a 6.'),
        'logged_at' => now()->setMicroseconds(0)->addSeconds(2),
    ]);
    LogEvent::factory()->create([
        'game_id' => 555,
        'event_type' => 'game_management_json',
        'raw_text' => metaMessageRawText('@Palice wins the game.'),
        'logged_at' => now()->setMicroseconds(0)->addSeconds(120),
    ]);

    SynthesizeGameLog::run($game->fresh());

    $log = GameLog::where('match_token', $match->token)->first();
    expect($log)->not->toBeNull()
        ->and($log->decoded_entries)->toHaveCount(3)
        ->and($log->decoded_entries[0]['message'])->toBe('@Palice rolled a 1.')
        ->and($log->decoded_entries[2]['message'])->toBe('@Palice wins the game.')
        ->and($log->first_timestamp)->not->toBeNull()
        ->and($log->decoded_at)->not->toBeNull();
});

it('returns without crashing when the game has no LogEvents', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 777]);

    SynthesizeGameLog::run($game->fresh());

    expect(GameLog::where('match_token', $match->token)->exists())->toBeFalse();
});

it('is idempotent — re-running updates the same GameLog row', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 888]);

    LogEvent::factory()->create([
        'game_id' => 888,
        'event_type' => 'game_management_json',
        'raw_text' => metaMessageRawText('@Palice rolled a 1.'),
        'logged_at' => now()->setMicroseconds(0),
    ]);

    SynthesizeGameLog::run($game->fresh());
    SynthesizeGameLog::run($game->fresh());

    expect(GameLog::where('match_token', $match->token)->count())->toBe(1);
});

it('skips events whose raw_text has no MetaMessage', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 999]);

    LogEvent::factory()->create([
        'game_id' => 999,
        'event_type' => 'game_management_json',
        'raw_text' => 'no meta here',
    ]);
    LogEvent::factory()->create([
        'game_id' => 999,
        'event_type' => 'game_management_json',
        'raw_text' => metaMessageRawText('@Palice rolled a 1.'),
    ]);

    SynthesizeGameLog::run($game->fresh());

    $log = GameLog::where('match_token', $match->token)->first();
    expect($log)->not->toBeNull()
        ->and($log->decoded_entries)->toHaveCount(1);
});
