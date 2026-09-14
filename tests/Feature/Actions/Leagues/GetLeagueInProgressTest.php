<?php

use App\Actions\Leagues\GetLeagueInProgress;
use App\Enums\LeagueState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inProgressDeck(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id, 'format' => 'CModern']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$deck, $version];
}

function inProgressLeague(DeckVersion $version, int $wins, int $losses, LeagueState $state = LeagueState::Active): League
{
    $league = League::factory()->create([
        'format' => 'CModern',
        'state' => $state,
        'started_at' => now()->subHours(2),
        'deck_version_id' => $version->id,
    ]);

    MtgoMatch::factory()->count($wins)->won()->create([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'started_at' => now()->subHour(),
    ]);
    MtgoMatch::factory()->count($losses)->lost()->create([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'started_at' => now()->subHour(),
    ]);

    return $league;
}

it('has nothing to show when no run is under way', function () {
    [$deck, $version] = inProgressDeck();

    inProgressLeague($version, 3, 2, LeagueState::Complete);

    expect(GetLeagueInProgress::run($deck))->toBeNull();
});

it('reports the run under way with the rounds it has left', function () {
    [$deck, $version] = inProgressDeck();

    inProgressLeague($version, 1, 1);

    $run = GetLeagueInProgress::run($deck);

    expect($run)->not->toBeNull()
        ->and($run['wins'])->toBe(1)
        ->and($run['losses'])->toBe(1)
        ->and($run['odds']['roundsLeft'])->toBe(3)
        ->and($run['odds']['winsForPrize'])->toBe(2);
});

it('predicts the run from this deck\'s own league record', function () {
    [$deck, $version] = inProgressDeck();

    // Twelve settled league matches, plus the live run's own 1-1, makes 10-4.
    $past = League::factory()->create([
        'format' => 'CModern', 'state' => LeagueState::Complete,
        'started_at' => now()->subDays(5), 'deck_version_id' => $version->id,
    ]);
    MtgoMatch::factory()->count(9)->won()->create([
        'deck_version_id' => $version->id, 'league_id' => $past->id, 'started_at' => now()->subDays(5),
    ]);
    MtgoMatch::factory()->count(3)->lost()->create([
        'deck_version_id' => $version->id, 'league_id' => $past->id, 'started_at' => now()->subDays(5),
    ]);

    inProgressLeague($version, 1, 1);

    $run = GetLeagueInProgress::run($deck);

    expect($run['oddsSource'])->toBe('league')
        ->and($run['winProbability'])->toBe(71);
});

it('falls back to the deck\'s overall record when it has barely played leagues', function () {
    [$deck, $version] = inProgressDeck();

    // Plenty of non-league matches at 50%, and only the live run's two league matches.
    MtgoMatch::factory()->count(10)->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(5)]);
    MtgoMatch::factory()->count(10)->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(5)]);

    inProgressLeague($version, 1, 1);

    $run = GetLeagueInProgress::run($deck);

    expect($run['oddsSource'])->toBe('deck')
        ->and($run['winProbability'])->toBe(50)
        ->and($run['odds']['chanceOfPrize'])->toBe(50);
});
