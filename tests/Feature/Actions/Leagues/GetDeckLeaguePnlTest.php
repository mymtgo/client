<?php

use App\Actions\Leagues\GetDeckLeaguePnl;
use App\Enums\LeagueState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pnlDeck(string $format = 'CModern'): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id, 'format' => $format]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$deck, $version];
}

/**
 * A league run holding `$wins` wins and `$losses` losses played on `$version`.
 */
function pnlLeague(DeckVersion $version, int $wins, int $losses, LeagueState $state = LeagueState::Complete, string $format = 'CModern'): League
{
    $league = League::factory()->create([
        'format' => $format,
        'state' => $state,
        'started_at' => now()->subDays(2),
        'deck_version_id' => $version->id,
    ]);

    MtgoMatch::factory()->count($wins)->won()->create([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'started_at' => now()->subDays(2),
    ]);
    MtgoMatch::factory()->count($losses)->lost()->create([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'started_at' => now()->subDays(2),
    ]);

    return $league;
}

it('adds up what the league runs cost and what they paid back', function () {
    [$deck, $version] = pnlDeck();

    pnlLeague($version, 3, 2);  // +2.62
    pnlLeague($version, 1, 4);  // -10.00

    $pnl = GetDeckLeaguePnl::run($deck, now()->subDays(7), now());

    expect($pnl['supported'])->toBeTrue()
        ->and($pnl['entries'])->toBe(2)
        ->and($pnl['tixSpent'])->toBe(20.0)
        ->and($pnl['net'])->toBe(-7.38)
        ->and($pnl['tixReturned'])->toBe(12.62)
        ->and($pnl['perEntry'])->toBe(-3.69);
});

it('reports the average wins per run against the break-even it needs', function () {
    [$deck, $version] = pnlDeck();

    pnlLeague($version, 3, 2);
    pnlLeague($version, 1, 4);

    $pnl = GetDeckLeaguePnl::run($deck, now()->subDays(7), now());

    expect($pnl['avgWins'])->toBe(2.0)
        ->and($pnl['breakEvenWins'])->toBe(2.7);
});

it('counts a clean run as a trophy', function () {
    [$deck, $version] = pnlDeck();

    pnlLeague($version, 5, 0);
    pnlLeague($version, 3, 2);

    $pnl = GetDeckLeaguePnl::run($deck, now()->subDays(7), now());

    expect($pnl['trophies'])->toBe(1);
});

it('leaves a run still in progress out of the accounting', function () {
    [$deck, $version] = pnlDeck();

    pnlLeague($version, 3, 2);
    pnlLeague($version, 1, 1, LeagueState::Active);

    $pnl = GetDeckLeaguePnl::run($deck, now()->subDays(7), now());

    expect($pnl['entries'])->toBe(1)
        ->and($pnl['net'])->toBe(2.62);
});

it('has nothing to report for a format the prize table does not price', function () {
    [$deck, $version] = pnlDeck('CLimited');

    pnlLeague($version, 2, 1, LeagueState::Complete, 'CLimited');

    $pnl = GetDeckLeaguePnl::run($deck, now()->subDays(7), now());

    expect($pnl['supported'])->toBeFalse()
        ->and($pnl['entries'])->toBe(0);
});

it('ignores runs started outside the range', function () {
    [$deck, $version] = pnlDeck();

    $old = pnlLeague($version, 5, 0);
    $old->update(['started_at' => now()->subDays(60)]);

    $pnl = GetDeckLeaguePnl::run($deck, now()->subDays(7), now());

    expect($pnl['entries'])->toBe(0);
});
