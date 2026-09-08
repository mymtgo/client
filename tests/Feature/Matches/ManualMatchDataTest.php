<?php

use App\Actions\Leagues\FormatLeagueRuns;
use App\Actions\Matches\BuildMatchShowProps;
use App\Data\Front\MatchData;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes manual on MatchData', function () {
    $match = MtgoMatch::factory()->create(['manual' => true]);

    expect(MatchData::from($match)->manual)->toBeTrue();
    expect(MatchData::from(MtgoMatch::factory()->create())->manual)->toBeFalse();
});

it('exposes manual from BuildMatchShowProps', function () {
    $version = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(['manual' => true, 'deck_version_id' => $version->id]);

    $props = BuildMatchShowProps::run($match);

    expect($props['manual'])->toBeTrue()
        ->and($props['imported'])->toBeFalse();
});

it('flags manual matches in formatted league runs', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'manual' => true,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]), null);

    expect($runs[0]['matches'][0]['manual'])->toBeTrue();
});
