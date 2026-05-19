<?php

use App\Actions\Reports\GetReportMatchCount;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('counts complete matches across given version ids for given format', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CPioneer', 'state' => 'complete']);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'in_progress']);

    expect(GetReportMatchCount::run([$version->id], 'CModern', null, null))->toBe(2);
});

it('returns zero when given no versions', function () {
    expect(GetReportMatchCount::run([], 'CModern', null, null))->toBe(0);
});

it('respects timeframe bounds', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
        'started_at' => now()->subDays(20),
    ]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
        'started_at' => now()->subDays(2),
    ]);

    expect(GetReportMatchCount::run([$version->id], 'CModern', now()->subDays(7), now()))->toBe(1);
});

it('counts across multiple versions', function () {
    $deck = Deck::factory()->create();
    $v1 = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $v2 = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create(['deck_version_id' => $v1->id, 'format' => 'CModern', 'state' => 'complete']);
    MtgoMatch::factory()->create(['deck_version_id' => $v2->id, 'format' => 'CModern', 'state' => 'complete']);

    expect(GetReportMatchCount::run([$v1->id, $v2->id], 'CModern', null, null))->toBe(2);
});
