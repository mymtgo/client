<?php

use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('interprets filter_from and filter_to as local calendar days in the system timezone', function () {
    AppSettings::setSystemTimezone('America/Los_Angeles');
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    // 05:00 UTC on 7 Sep is 22:00 PDT on 6 Sep: outside a local 7 Sep filter.
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => Carbon::parse('2026-09-07 05:00:00', 'UTC'),
    ]);
    // 08:00 UTC on 7 Sep is 01:00 PDT on 7 Sep: inside.
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => Carbon::parse('2026-09-07 08:00:00', 'UTC'),
    ]);
    // 05:00 UTC on 8 Sep is 22:00 PDT on 7 Sep: inside.
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $version->id,
        'started_at' => Carbon::parse('2026-09-08 05:00:00', 'UTC'),
    ]);
    // 06:30 UTC on 8 Sep is 23:30 PDT on 7 Sep: inside.
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $version->id,
        'started_at' => Carbon::parse('2026-09-08 06:30:00', 'UTC'),
    ]);

    $this->get(route('decks.matches', [
        'deck' => $deck->id,
        'filter_from' => '2026-09-07',
        'filter_to' => '2026-09-07',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Matches')
            ->has('matches.data', 3)
        );
});
