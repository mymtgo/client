<?php

use App\Data\Front\DeckGroupStatsData;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function makeDeckWithCounts(int $won, int $lost): Deck
{
    $deck = Deck::factory()->create();
    $deck->setAttribute('won_matches_count', $won);
    $deck->setAttribute('lost_matches_count', $lost);
    $deck->setAttribute('matches_count', $won + $lost);

    return $deck;
}

function makeDeckWithLastPlayed(?string $timestamp): Deck
{
    $deck = Deck::factory()->create();
    $deck->setAttribute('matches_count', 0);
    $deck->setAttribute('won_matches_count', 0);
    $deck->setAttribute('lost_matches_count', 0);
    $deck->setAttribute('matches_max_started_at', $timestamp);

    return $deck;
}

it('computes weighted winrate across multiple decks', function () {
    $decks = new Collection([
        makeDeckWithCounts(won: 6, lost: 4),   // 10 matches, 60% wins
        makeDeckWithCounts(won: 18, lost: 2),  // 20 matches, 90% wins
    ]);

    $stats = DeckGroupStatsData::fromDecks($decks);

    expect($stats->totalMatches)->toBe(30);
    expect($stats->totalWins)->toBe(24);
    expect($stats->winrate)->toBe(80.0);
    expect($stats->lastPlayedAt)->toBeNull();
});

it('returns null winrate when no matches played', function () {
    $decks = new Collection([
        makeDeckWithCounts(won: 0, lost: 0),
        makeDeckWithCounts(won: 0, lost: 0),
    ]);

    $stats = DeckGroupStatsData::fromDecks($decks);

    expect($stats->totalMatches)->toBe(0);
    expect($stats->totalWins)->toBe(0);
    expect($stats->winrate)->toBeNull();
    expect($stats->lastPlayedAt)->toBeNull();
});

it('handles an empty collection', function () {
    $stats = DeckGroupStatsData::fromDecks(new Collection);

    expect($stats->totalMatches)->toBe(0);
    expect($stats->totalWins)->toBe(0);
    expect($stats->winrate)->toBeNull();
    expect($stats->lastPlayedAt)->toBeNull();
});

it('computes lastPlayedAt from the max matches_max_started_at across decks', function () {
    $decks = new Collection([
        makeDeckWithLastPlayed('2026-03-01 12:00:00'),
        makeDeckWithLastPlayed('2026-04-15 09:30:00'),
        makeDeckWithLastPlayed('2025-12-20 18:00:00'),
    ]);

    $stats = DeckGroupStatsData::fromDecks($decks);

    expect($stats->lastPlayedAt)->not->toBeNull();
    expect($stats->lastPlayedAt->toDateTimeString())->toBe('2026-04-15 09:30:00');
});

it('returns null lastPlayedAt when no decks have been played', function () {
    $decks = new Collection([
        makeDeckWithLastPlayed(null),
        makeDeckWithLastPlayed(null),
    ]);

    $stats = DeckGroupStatsData::fromDecks($decks);

    expect($stats->lastPlayedAt)->toBeNull();
});
