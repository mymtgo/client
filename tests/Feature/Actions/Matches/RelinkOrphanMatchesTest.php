<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOrphanMatch(bool $linkable = true, array $matchOverrides = []): MtgoMatch
{
    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $card = Card::factory()->create([
        'mtgo_id' => 100,
        'oracle_id' => 'oracle-fixed-id',
    ]);

    $signature = GenerateDeckSignature::run(collect([[
        'mtgo_id' => $card->mtgo_id,
        'quantity' => 4,
        'sideboard' => 'false',
    ]]));

    if ($linkable) {
        $deck = Deck::factory()->create(['account_id' => $account->id]);
        DeckVersion::factory()->create([
            'deck_id' => $deck->id,
            'signature' => $signature,
        ]);
    }

    $match = MtgoMatch::factory()->create(array_merge([
        'state' => MatchState::Complete,
        'deck_version_id' => null,
        'ended_at' => now()->subMinutes(5),
    ], $matchOverrides));

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([[
        'CatalogId' => $card->mtgo_id,
        'Quantity' => 4,
        'InSideboard' => false,
    ]]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    return $match;
}

it('relinks an orphan match when a matching deck version exists', function () {
    $match = makeOrphanMatch(linkable: true);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('leaves orphan matches unlinked when no matching deck version exists', function () {
    $match = makeOrphanMatch(linkable: false);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('does not touch matches that already have a deck linked', function () {
    $match = makeOrphanMatch(linkable: true);

    RelinkOrphanMatches::run();
    $linkedId = $match->fresh()->deck_version_id;
    expect($linkedId)->not->toBeNull();

    RelinkOrphanMatches::run();
    expect($match->fresh()->deck_version_id)->toBe($linkedId);
});

it('ignores matches in Started state', function () {
    $match = MtgoMatch::factory()->started()->create([
        'deck_version_id' => null,
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('ignores orphans outside the recency window', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'started_at' => now()->subDays(30),
        'ended_at' => now()->subDays(30),
    ]);

    RelinkOrphanMatches::run(withinDays: 7);

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('relinks an orphan match in InProgress state', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'state' => MatchState::InProgress,
        'started_at' => now()->subMinutes(5),
        'ended_at' => null,
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('relinks an orphan match in Ended state', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'state' => MatchState::Ended,
        'started_at' => now()->subMinutes(5),
        'ended_at' => now()->subMinutes(1),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('skips matches outside the recency window by started_at', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'state' => MatchState::Complete,
        'started_at' => now()->subDays(30),
        'ended_at' => now()->subDays(30),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});
