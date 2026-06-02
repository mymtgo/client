<?php

use App\Actions\Cards\ComputeDrawOdds;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signatureFor(array $rows): string
{
    // $rows: [[mtgoId, qty, sideboardFlag], ...]
    $sig = collect($rows)
        ->map(fn ($r) => "{$r[0]}:{$r[1]}:{$r[2]}")
        ->implode('|');

    return base64_encode($sig);
}

it('returns null when the match has no deck version', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '400001',
        'token' => 'mt-d1',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    expect(ComputeDrawOdds::run($match))->toBeNull();
});

it('computes full-deck draw chances when there is no game timeline', function () {
    Card::create(['mtgo_id' => '101', 'oracle_id' => 'o-mountain', 'name' => 'Mountain', 'type' => 'Basic Land']);
    Card::create(['mtgo_id' => '102', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);

    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => signatureFor([['101', '20', '0'], ['102', '4', '0']]),
        'modified_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400002',
        'token' => 'mt-d2',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'started_at' => now(),
        'deck_version_id' => $deckVersion->id,
    ]);

    $result = ComputeDrawOdds::run($match);

    expect($result)->not->toBeNull();
    expect($result->librarySize)->toBe(24);

    $bolt = collect($result->cards->all())->firstWhere('name', 'Lightning Bolt');
    expect($bolt->remaining)->toBe(4);
    expect($bolt->total)->toBe(4);
    expect(round($bolt->drawChance, 4))->toBe(round(4 / 24, 4));
});
