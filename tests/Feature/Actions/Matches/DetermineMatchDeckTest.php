<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Matches\DetermineMatchDeck;
use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function setupDeckLinkScenario(array $xmlCards, array $logCards): array
{
    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    foreach ($xmlCards as $card) {
        Card::factory()->create([
            'mtgo_id' => $card['mtgo_id'],
            'oracle_id' => $card['oracle_id'] ?? "oracle-{$card['mtgo_id']}",
        ]);
    }

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $signature = GenerateDeckSignature::run(collect($xmlCards));
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode(array_map(fn ($c) => [
        'CatalogId' => $c['mtgo_id'],
        'Quantity' => $c['quantity'],
        'InSideboard' => $c['sideboard'] === 'true',
    ], $logCards));

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    return ['match' => $match, 'version' => $version];
}

it('links a match when log card order differs from XML order', function () {
    $cards = [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1002, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1003, 'quantity' => 2, 'sideboard' => 'true'],
    ];
    $reordered = [
        $cards[2], $cards[0], $cards[1],
    ];

    ['match' => $match, 'version' => $version] = setupDeckLinkScenario($cards, $reordered);

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBe($version->id);
});

it('links a match when oracle_id was unresolved at deck-sync time', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => null]);

    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => GenerateDeckSignature::run(collect([
            ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ])),
    ]);

    Card::where('mtgo_id', 1001)->update(['oracle_id' => 'oracle-1001-resolved']);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([[
        'CatalogId' => 1001,
        'Quantity' => 4,
        'InSideboard' => false,
    ]]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBe($version->id);
});

it('does not link near-duplicate decks (one card different)', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => GenerateDeckSignature::run(collect([
            ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ])),
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([
        ['CatalogId' => 1001, 'Quantity' => 3, 'InSideboard' => false],
        ['CatalogId' => 1002, 'Quantity' => 1, 'InSideboard' => false],
    ]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('logs diagnostic context when no deck version matches', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);

    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([[
        'CatalogId' => 1001,
        'Quantity' => 4,
        'InSideboard' => false,
    ]]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    Log::shouldReceive('channel')
        ->with('pipeline')
        ->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->with(Mockery::pattern('/no deck version match/i'), Mockery::on(function ($context) use ($match) {
            return $context['match_id'] === $match->id
                && array_key_exists('computed_signature', $context)
                && array_key_exists('candidate_versions', $context)
                && $context['card_count'] === 1;
        }));

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('links a match to a soft-deleted deck', function () {
    $cards = [
        ['mtgo_id' => 2001, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 2002, 'quantity' => 4, 'sideboard' => 'false'],
    ];

    ['match' => $match, 'version' => $version] = setupDeckLinkScenario($cards, $cards);

    Deck::find($version->deck_id)->delete();

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBe($version->id);
});

it('links a match when MTGO played a different printing of the same card', function () {
    // CatalogIds are printing-specific, so a reprint the .dek file does not
    // reference produces a signature that matches nothing even though it is
    // the same 75 cards.
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-basilisk-gate']);
    Card::factory()->create(['mtgo_id' => 9001, 'oracle_id' => 'oracle-basilisk-gate']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-dust-to-dust']);

    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => GenerateDeckSignature::run(collect([
            ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
            ['mtgo_id' => 1002, 'quantity' => 2, 'sideboard' => 'true'],
        ])),
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    // Same list, but the log reports the other printing of the maindeck card.
    $deckJson = json_encode([
        ['CatalogId' => 9001, 'Quantity' => 4, 'InSideboard' => false],
        ['CatalogId' => 1002, 'Quantity' => 2, 'InSideboard' => true],
    ]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBe($version->id);
});

it('does not link by oracle identity when the lists actually differ', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 9001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => GenerateDeckSignature::run(collect([
            ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
            ['mtgo_id' => 1002, 'quantity' => 2, 'sideboard' => 'false'],
        ])),
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([
        ['CatalogId' => 9001, 'Quantity' => 3, 'InSideboard' => false],
        ['CatalogId' => 1002, 'Quantity' => 2, 'InSideboard' => false],
    ]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('refuses to guess when two different decks share an oracle identity', function () {
    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 9001, 'oracle_id' => 'oracle-1001']);

    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $signature = GenerateDeckSignature::run(collect([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
    ]));

    foreach (range(1, 2) as $ignored) {
        DeckVersion::factory()->create([
            'deck_id' => Deck::factory()->create(['account_id' => $account->id])->id,
            'signature' => $signature,
        ]);
    }

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([
        ['CatalogId' => 9001, 'Quantity' => 4, 'InSideboard' => false],
    ]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    DetermineMatchDeck::run($match);

    expect($match->fresh()->deck_version_id)->toBeNull();
});
