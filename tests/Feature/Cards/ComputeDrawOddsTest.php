<?php

use App\Actions\Cards\ComputeDrawOdds;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
