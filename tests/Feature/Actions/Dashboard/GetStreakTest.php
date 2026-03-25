<?php

use App\Actions\Dashboard\GetStreak;
use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function streakAccount(): Account
{
    return Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
}

function streakMatch(DeckVersion $version, MatchOutcome $outcome, string $startedAt): MtgoMatch
{
    return MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'outcome' => $outcome,
        'started_at' => $startedAt,
        'ended_at' => now()->parse($startedAt)->addMinutes(30),
    ]);
}

it('returns zero streak when no matches exist', function () {
    $result = GetStreak::run(null, now()->subWeek(), now());

    expect($result)->toBe([
        'current' => null,
        'bestWin' => 0,
        'bestLoss' => 0,
    ]);
});

it('returns current win streak', function () {
    $account = streakAccount();
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    streakMatch($version, MatchOutcome::Loss, now()->subHours(5)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(4)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(3)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(2)->toDateTimeString());

    $result = GetStreak::run($account->id, now()->subWeek(), now());

    expect($result['current'])->toBe('3W');
    expect($result['bestWin'])->toBe(3);
});

it('returns current loss streak', function () {
    $account = streakAccount();
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    streakMatch($version, MatchOutcome::Win, now()->subHours(3)->toDateTimeString());
    streakMatch($version, MatchOutcome::Loss, now()->subHours(2)->toDateTimeString());
    streakMatch($version, MatchOutcome::Loss, now()->subHours(1)->toDateTimeString());

    $result = GetStreak::run($account->id, now()->subWeek(), now());

    expect($result['current'])->toBe('2L');
    expect($result['bestLoss'])->toBe(2);
});

it('draws break the streak', function () {
    $account = streakAccount();
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    streakMatch($version, MatchOutcome::Win, now()->subHours(4)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(3)->toDateTimeString());
    streakMatch($version, MatchOutcome::Draw, now()->subHours(2)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(1)->toDateTimeString());

    $result = GetStreak::run($account->id, now()->subWeek(), now());

    expect($result['current'])->toBe('1W');
    expect($result['bestWin'])->toBe(2);
});

it('unknown outcomes break the streak', function () {
    $account = streakAccount();
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    streakMatch($version, MatchOutcome::Win, now()->subHours(3)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(2)->toDateTimeString());
    streakMatch($version, MatchOutcome::Unknown, now()->subHours(1)->toDateTimeString());

    $result = GetStreak::run($account->id, now()->subWeek(), now());

    expect($result['current'])->toBeNull();
    expect($result['bestWin'])->toBe(2);
});

it('computes all-time best streaks across full history', function () {
    $account = streakAccount();
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    for ($i = 10; $i > 5; $i--) {
        streakMatch($version, MatchOutcome::Win, now()->subDays($i)->toDateTimeString());
    }
    streakMatch($version, MatchOutcome::Loss, now()->subDays(5)->toDateTimeString());

    streakMatch($version, MatchOutcome::Win, now()->subHours(2)->toDateTimeString());
    streakMatch($version, MatchOutcome::Win, now()->subHours(1)->toDateTimeString());

    $result = GetStreak::run($account->id, now()->subDays(3), now());

    expect($result['current'])->toBe('2W');
    expect($result['bestWin'])->toBe(5);
});
