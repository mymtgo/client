<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::setDebugMode(true);
});

function simulatorFixture(): array
{
    Card::create(['mtgo_id' => '301', 'oracle_id' => 'o-island', 'name' => 'Island', 'type' => 'Basic Land']);
    $bolt = Card::create(['mtgo_id' => '302', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create(['name' => 'My Deck']);
    DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => base64_encode('301:20:0|302:4:0'),
        'modified_at' => now(),
    ]);

    $archetype = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern']);
    $decklist = ArchetypeDeck::factory()->create(['archetype_id' => $archetype->id, 'last_synced_at' => now()]);
    $decklist->cards()->attach($bolt->id, ['quantity' => 4, 'sideboard' => false]);

    return [$deck, $archetype];
}

it('creates a fake in-progress match with revealed opponent cards', function () {
    [$deck, $archetype] = simulatorFixture();

    $this->post(route('debug.overlay.store'), [
        'deck_id' => $deck->id,
        'archetype_id' => $archetype->id,
        'opponent_name' => 'TestOpp',
    ])->assertRedirect();

    $match = MtgoMatch::where('token', 'like', 'fake-overlay-%')->sole();

    expect($match->state)->toBe(MatchState::InProgress);
    expect($match->deck_version_id)->not->toBeNull();

    $opponent = $match->games()->first()->opponents()->first();

    expect($opponent->username)->toBe('TestOpp');
    expect($opponent->pivot->deck_json)->toBe([['mtgo_id' => 302, 'quantity' => 4]]);
});

it('drives the sideboarding phase and back', function () {
    [$deck, $archetype] = simulatorFixture();

    $this->post(route('debug.overlay.store'), [
        'deck_id' => $deck->id,
        'archetype_id' => $archetype->id,
    ]);

    $this->post(route('debug.overlay.phase'), ['phase' => 'sideboarding'])->assertRedirect();

    $this->get(route('overlay.game'))
        ->assertInertia(fn ($page) => $page->where('isSideboarding', true));

    // The planted log event must never be picked up by the pipeline.
    expect(LogEvent::where('match_token', 'like', 'fake-overlay-%')->whereNull('processed_at')->exists())
        ->toBeFalse();

    $this->post(route('debug.overlay.phase'), ['phase' => 'game2'])->assertRedirect();

    $match = MtgoMatch::where('token', 'like', 'fake-overlay-%')->sole();

    expect($match->games()->count())->toBe(2);

    $this->get(route('overlay.game'))
        ->assertInertia(fn ($page) => $page->where('isSideboarding', false));
});

it('tears every fake artefact down', function () {
    [$deck, $archetype] = simulatorFixture();

    $this->post(route('debug.overlay.store'), [
        'deck_id' => $deck->id,
        'archetype_id' => $archetype->id,
    ]);
    $this->post(route('debug.overlay.phase'), ['phase' => 'sideboarding']);

    $this->delete(route('debug.overlay.destroy'))->assertRedirect();

    expect(MtgoMatch::where('token', 'like', 'fake-overlay-%')->exists())->toBeFalse();
    expect(LogEvent::where('match_token', 'like', 'fake-overlay-%')->exists())->toBeFalse();
});

it('is unreachable outside debug mode', function () {
    AppSettings::setDebugMode(false);

    $this->get(route('debug.overlay.index'))->assertRedirect('/');
});
