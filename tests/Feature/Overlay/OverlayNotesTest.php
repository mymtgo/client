<?php

use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\SideboardGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function notableMatch(): array
{
    $deck = Deck::factory()->create(['name' => 'My Murktide']);

    $version = DeckVersion::create([
        'deck_id' => $deck->id, 'signature' => '', 'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '820001', 'token' => 'mt-notes', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $opponent = Player::create(['username' => 'notesOpp']);

    $game = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-notes', 'started_at' => now()]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2']);

    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $archetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    return [$deck, $archetype];
}

it('stores a note against the live match deck and archetype', function () {
    [$deck, $archetype] = notableMatch();

    $this->post(route('overlay.notes.store'), ['body' => 'Keep Rest in Peace, cut Cut Down'])
        ->assertRedirect();

    $note = DeckArchetypeNote::sole();

    expect($note->deck_id)->toBe($deck->id);
    expect($note->archetype_id)->toBe($archetype->id);
    expect($note->body)->toBe('Keep Rest in Peace, cut Cut Down');
});

it('requires a body', function () {
    notableMatch();

    $this->post(route('overlay.notes.store'), ['body' => ''])
        ->assertSessionHasErrors('body');
});

it('rejects a body over two thousand characters', function () {
    notableMatch();

    $this->post(route('overlay.notes.store'), ['body' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('body');
});

it('does not store a note when no archetype is resolved', function () {
    $deck = Deck::factory()->create();

    $version = DeckVersion::create(['deck_id' => $deck->id, 'signature' => '', 'modified_at' => now()]);

    MtgoMatch::create([
        'mtgo_id' => '820002', 'token' => 'mt-no-arch', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $version->id,
    ]);

    $this->post(route('overlay.notes.store'), ['body' => 'Orphan note'])->assertRedirect();

    expect(DeckArchetypeNote::count())->toBe(0);
});

it('deletes a note', function () {
    notableMatch();

    $note = DeckArchetypeNote::factory()->create(['body' => 'Delete me']);

    $this->delete(route('overlay.notes.destroy', ['note' => $note->id]))->assertRedirect();

    expect(DeckArchetypeNote::whereKey($note->id)->exists())->toBeFalse();
});

it('creates a sideboard guide in the background when noting an unguided matchup', function () {
    [$deck, $archetype] = notableMatch();

    $this->post(route('overlay.notes.store'), ['body' => 'Board in Rest in Peace'])
        ->assertRedirect();

    $guide = SideboardGuide::sole();

    expect($guide->deck_id)->toBe($deck->id);
    expect($guide->archetype_id)->toBe($archetype->id);
});

it('does not create a second guide when one already exists for the matchup', function () {
    [$deck, $archetype] = notableMatch();

    SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    $this->post(route('overlay.notes.store'), ['body' => 'Board in Rest in Peace'])
        ->assertRedirect();

    expect(SideboardGuide::count())->toBe(1);
});
