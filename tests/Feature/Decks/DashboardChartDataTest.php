<?php

use App\Enums\MatchOutcome;
use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('buckets chart data by day in the system timezone rather than UTC', function () {
    AppSettings::setSystemTimezone('America/Los_Angeles');
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    // 03:00 UTC on the 8th is 20:00 PDT on the 7th.
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => Carbon::parse('2026-09-08 03:00:00', 'UTC'),
    ]);
    // A draw on the 7th (03:30 UTC on the 8th) is not a loss, but it is a match played.
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'outcome' => MatchOutcome::Draw,
        'started_at' => Carbon::parse('2026-09-08 03:30:00', 'UTC'),
    ]);
    // 17:00 UTC on the 8th is 10:00 PDT on the 8th.
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $version->id,
        'started_at' => Carbon::parse('2026-09-08 17:00:00', 'UTC'),
    ]);

    $response = $this->get(route('decks.show', ['deck' => $deck->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('decks/Dashboard')
        ->where('chartData.0.date', '2026-09-07')
        ->where('chartData.0.wins', 1)
        ->where('chartData.0.losses', 0)
        ->where('chartData.0.draws', 1)
        ->where('chartData.0.winrate', '50')
        ->where('chartData.1.date', '2026-09-08')
        ->where('chartData.1.wins', 0)
        ->where('chartData.1.losses', 1)
        ->where('chartData.1.draws', 0)
    );
});
