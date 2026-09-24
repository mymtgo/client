<?php

use App\Actions\Leagues\GetLeagueResultDistribution;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->deck = Deck::factory()->create();
    $this->version = DeckVersion::factory()->for($this->deck)->create();
});

function seedDeckLeague(DeckVersion $version, LeagueState $state, int $wins, int $losses, LeagueKind $kind = LeagueKind::Constructed): League
{
    $league = League::factory()->create([
        'deck_version_id' => $version->id,
        'state' => $state,
        'kind' => $kind,
    ]);

    for ($i = 0; $i < $wins; $i++) {
        MtgoMatch::factory()->won()->create(['league_id' => $league->id, 'deck_version_id' => $version->id]);
    }
    for ($i = 0; $i < $losses; $i++) {
        MtgoMatch::factory()->lost()->create(['league_id' => $league->id, 'deck_version_id' => $version->id]);
    }

    return $league;
}

function deckMatchIds(DeckVersion $version)
{
    return MtgoMatch::where('deck_version_id', $version->id)->pluck('id');
}

it('returns empty buckets and no drops without leagues', function () {
    $result = GetLeagueResultDistribution::run($this->deck, collect());

    expect($result)->toBe(['5-0' => 0, '4-1' => 0, '3-2' => 0, '2-3' => 0, '1-4' => 0, '0-5' => 0, 'dropped' => 0]);
});

it('counts dropped leagues separately from finishes', function () {
    seedDeckLeague($this->version, LeagueState::Complete, 4, 1);
    seedDeckLeague($this->version, LeagueState::Dropped, 1, 2);
    seedDeckLeague($this->version, LeagueState::Dropped, 0, 1);

    $result = GetLeagueResultDistribution::run($this->deck, deckMatchIds($this->version));

    expect($result['4-1'])->toBe(1)
        ->and($result['dropped'])->toBe(2)
        ->and($result['1-4'])->toBe(0);
});

it('does not count partial or draft leagues as drops', function () {
    seedDeckLeague($this->version, LeagueState::Partial, 1, 1);
    seedDeckLeague($this->version, LeagueState::Dropped, 1, 1, LeagueKind::Draft);

    $result = GetLeagueResultDistribution::run($this->deck, deckMatchIds($this->version));

    expect($result['dropped'])->toBe(0);
});
