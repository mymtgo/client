<?php

use App\Actions\Leagues\GetManualMatchLeagueOptions;
use App\Enums\LeagueKind;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->deck = Deck::factory()->create();
    $this->version = DeckVersion::factory()->create(['deck_id' => $this->deck->id]);
});

it('lists constructed leagues with room left, newest first', function () {
    $older = League::factory()->create(['deck_version_id' => $this->version->id, 'name' => 'Older', 'started_at' => now()->subDay()]);
    $newer = League::factory()->create(['deck_version_id' => $this->version->id, 'name' => 'Newer', 'started_at' => now()]);
    MtgoMatch::factory()->count(2)->create(['league_id' => $newer->id, 'deck_version_id' => $this->version->id, 'state' => MatchState::Complete]);

    $options = GetManualMatchLeagueOptions::run();

    expect(array_column($options, 'id'))->toBe([$newer->id, $older->id])
        ->and($options[0])->toMatchArray(['name' => 'Newer', 'deckId' => $this->deck->id, 'matchCount' => 2, 'roundCount' => 5]);
});

it('drops full leagues, limited leagues and soft-deleted leagues', function () {
    $full = League::factory()->create(['deck_version_id' => $this->version->id]);
    MtgoMatch::factory()->count(5)->create(['league_id' => $full->id, 'deck_version_id' => $this->version->id, 'state' => MatchState::Complete]);
    League::factory()->create(['deck_version_id' => $this->version->id, 'kind' => LeagueKind::Draft]);
    League::factory()->create(['deck_version_id' => $this->version->id])->delete();
    $open = League::factory()->create(['deck_version_id' => $this->version->id]);

    expect(array_column(GetManualMatchLeagueOptions::run(), 'id'))->toBe([$open->id]);
});

it('filters to the deck when given, keeping leagues with no deck version', function () {
    $otherVersion = DeckVersion::factory()->create();
    League::factory()->create(['deck_version_id' => $otherVersion->id]);
    $mine = League::factory()->create(['deck_version_id' => $this->version->id]);
    $unlinked = League::factory()->create(['deck_version_id' => null]);

    $ids = array_column(GetManualMatchLeagueOptions::run($this->deck->id), 'id');

    expect($ids)->toContain($mine->id, $unlinked->id)->toHaveCount(2);
});

it('finds an open league buried under more than fifty full runs', function () {
    $open = League::factory()->create(['deck_version_id' => $this->version->id, 'started_at' => now()->subYear()]);

    foreach (range(1, 55) as $i) {
        $full = League::factory()->create(['deck_version_id' => $this->version->id, 'started_at' => now()->subDays($i)]);
        MtgoMatch::factory()->count(5)->create(['league_id' => $full->id, 'deck_version_id' => $this->version->id, 'state' => MatchState::Complete]);
    }

    expect(array_column(GetManualMatchLeagueOptions::run(), 'id'))->toBe([$open->id]);
});
