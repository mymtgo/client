<?php

use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function popoutDrawOddsSignature(array $rows): string
{
    return base64_encode(collect($rows)->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")->implode('|'));
}

it('renders draw odds for the active match deck', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => popoutDrawOddsSignature([['101', '20', '0'], ['102', '4', '0']]),
        'modified_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '500001', 'token' => 'mt-s1', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress,
        'started_at' => now(), 'deck_version_id' => $deckVersion->id,
    ]);

    $this->get(route('decks.popout'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Popout')
            ->where('drawOdds.librarySize', 24)
            ->has('drawOdds.cards', 2)
        );
});

it('renders null draw odds when no active match exists', function () {
    $this->get(route('decks.popout'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/Popout')
            ->where('drawOdds', null)
        );
});
