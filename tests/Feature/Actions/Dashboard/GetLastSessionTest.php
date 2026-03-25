<?php

use App\Actions\Dashboard\GetLastSession;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupSessionAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$account, $version];
}

it('returns null when no matches exist', function () {
    expect(GetLastSession::run(null))->toBeNull();
});

it('groups matches within 2 hours as one session', function () {
    [$account, $version] = setupSessionAccount();
    $m1 = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(3),
        'ended_at' => now()->subHours(3)->addMinutes(30),
    ]);
    $m2 = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(2)->subMinutes(20),
        'ended_at' => now()->subHours(2)->addMinutes(10),
    ]);
    $m3 = MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(1)->subMinutes(40),
        'ended_at' => now()->subHour()->subMinutes(10),
    ]);

    \App\Models\Game::create(['match_id' => $m1->id, 'mtgo_id' => 1, 'started_at' => $m1->started_at, 'won' => true]);
    \App\Models\Game::create(['match_id' => $m1->id, 'mtgo_id' => 2, 'started_at' => $m1->started_at, 'won' => true]);
    \App\Models\Game::create(['match_id' => $m3->id, 'mtgo_id' => 3, 'started_at' => $m3->started_at, 'won' => false]);
    \App\Models\Game::create(['match_id' => $m3->id, 'mtgo_id' => 4, 'started_at' => $m3->started_at, 'won' => false]);

    $result = GetLastSession::run($account->id);
    expect($result)->not->toBeNull();
    expect($result['matches'])->toHaveCount(3);
    expect($result['record'])->toBe('2-1');
    expect($result['matches'][0]['gamesWon'])->toBe(2);
    expect($result['matches'][2]['gamesLost'])->toBe(2);
});

it('splits sessions with >2 hour gaps', function () {
    [$account, $version] = setupSessionAccount();
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(6),
        'ended_at' => now()->subHours(6)->addMinutes(30),
    ]);
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(30),
    ]);
    $result = GetLastSession::run($account->id);
    expect($result['matches'])->toHaveCount(1);
    expect($result['record'])->toBe('0-1');
});

it('handles null ended_at with fallback', function () {
    [$account, $version] = setupSessionAccount();
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(2),
        'ended_at' => null,
    ]);
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(30),
    ]);
    $result = GetLastSession::run($account->id);
    expect($result['matches'])->toHaveCount(2);
});
