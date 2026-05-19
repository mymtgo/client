<?php

use App\Actions\Reports\GetReportArchetypeStats;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Account::flushCurrent();
});

it('returns null when archetype or format missing', function () {
    expect(GetReportArchetypeStats::run(null, 'CModern', [1, 2], null, null))->toBeNull()
        ->and(GetReportArchetypeStats::run(1, null, [1, 2], null, null))->toBeNull()
        ->and(GetReportArchetypeStats::run(1, 'CModern', [], null, null))->toBeNull();
});

it('computes deck count, match record, and winrate', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create(['name' => 'Test', 'color_identity' => 'UR']);

    $deckA = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $versionA = DeckVersion::factory()->create(['deck_id' => $deckA->id]);

    $deckB = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $versionB = DeckVersion::factory()->create(['deck_id' => $deckB->id]);

    MtgoMatch::factory()->create(['deck_version_id' => $versionA->id, 'format' => 'CModern', 'state' => 'complete', 'outcome' => 'win']);
    MtgoMatch::factory()->create(['deck_version_id' => $versionA->id, 'format' => 'CModern', 'state' => 'complete', 'outcome' => 'win']);
    MtgoMatch::factory()->create(['deck_version_id' => $versionB->id, 'format' => 'CModern', 'state' => 'complete', 'outcome' => 'loss']);

    $stats = GetReportArchetypeStats::run($archetype->id, 'CModern', [$versionA->id, $versionB->id], null, null);

    expect($stats)->not->toBeNull()
        ->and($stats['deckCount'])->toBe(2)
        ->and($stats['matchWins'])->toBe(2)
        ->and($stats['matchLosses'])->toBe(1)
        ->and($stats['matchDraws'])->toBe(0)
        ->and($stats['matchWinrate'])->toBe(67)
        ->and($stats['formatLabel'])->toBe('Modern')
        ->and($stats['archetypeName'])->toBe('Test')
        ->and($stats['colorIdentity'])->toBe('UR');
});

it('returns zero winrate when no decisive matches', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete', 'outcome' => 'draw']);

    $stats = GetReportArchetypeStats::run($archetype->id, 'CModern', [$version->id], null, null);

    expect($stats['matchWinrate'])->toBe(0)
        ->and($stats['matchDraws'])->toBe(1);
});
