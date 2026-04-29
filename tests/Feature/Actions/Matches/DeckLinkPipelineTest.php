<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Enums\MatchState;
use App\Jobs\RegenerateDeckSignaturesJob;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('relinks an InProgress orphan with reordered log cards via canonical signature', function () {
    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);
    Card::factory()->create(['mtgo_id' => 1003, 'oracle_id' => 'oracle-1003']);

    $xmlOrder = [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1002, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1003, 'quantity' => 2, 'sideboard' => 'true'],
    ];

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => GenerateDeckSignature::run(collect($xmlOrder)),
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
        'started_at' => now()->subMinutes(5),
        'ended_at' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(5),
    ]);

    $logOrder = [
        ['CatalogId' => 1003, 'Quantity' => 2, 'InSideboard' => true],
        ['CatalogId' => 1001, 'Quantity' => 4, 'InSideboard' => false],
        ['CatalogId' => 1002, 'Quantity' => 4, 'InSideboard' => false],
    ];

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => '12:00:00 [INF] (Deck|Used) '.json_encode($logOrder),
        'logged_at' => now()->subMinutes(5),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBe($version->id);
});

it('deploy-window: legacy-sig DeckVersion becomes linkable after backfill job runs', function () {
    // Simulates a fresh deploy: existing DeckVersions have legacy oracle_id-
    // anchored signatures, an orphan InProgress match arrived during the gap.
    // RegenerateDeckSignaturesJob (run by RegenerateDeckSignatures app update
    // on next boot) rewrites the signature to canonical mtgo_id form, after
    // which RelinkOrphanMatches can match the log-derived signature.
    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    // Legacy signature: oracle_id anchored, original join order preserved.
    $legacySignature = base64_encode('oracle-1001:4:false|oracle-1002:3:false');

    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $legacySignature,
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'deck_version_id' => null,
        'started_at' => now()->subMinutes(5),
        'ended_at' => null,
    ]);

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(5),
    ]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => '12:00:00 [INF] (Deck|Used) '.json_encode([
            ['CatalogId' => 1001, 'Quantity' => 4, 'InSideboard' => false],
            ['CatalogId' => 1002, 'Quantity' => 3, 'InSideboard' => false],
        ]),
        'logged_at' => now()->subMinutes(5),
    ]);

    // Pre-backfill: log produces canonical signature, DB still legacy → miss.
    RelinkOrphanMatches::run();
    expect($match->fresh()->deck_version_id)->toBeNull();
    expect($version->fresh()->signature)->toBe($legacySignature);

    // Backfill — rewrites to canonical.
    (new RegenerateDeckSignaturesJob)->handle();

    $expectedCanonical = GenerateDeckSignature::run(collect([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => 'false'],
        ['mtgo_id' => 1002, 'quantity' => 3, 'sideboard' => 'false'],
    ]));

    expect($version->fresh()->signature)->toBe($expectedCanonical);

    // Post-backfill: relink succeeds.
    RelinkOrphanMatches::run();
    expect($match->fresh()->deck_version_id)->toBe($version->id);
});
