<?php

use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::set('debug_mode', true);
});

function createMatchWithGamesAndOpponent(int $count = 5): void
{
    $opponent = Opponent::firstOrCreate(['username' => 'TestOpponent']);

    for ($i = 0; $i < $count; $i++) {
        $match = MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);
        Game::factory()->create(['match_id' => $match->id, 'opp_instance' => 1]);
    }
}

it('loads debug games page with bounded query count', function () {
    createMatchWithGamesAndOpponent(10);

    DB::enableQueryLog();
    $this->get('/debug/games')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // With addSelect, should be well under 20 queries (was 401+ before)
    expect($queryCount)->toBeLessThan(20);
});

it('returns matchOptions with opponent names', function () {
    createMatchWithGamesAndOpponent(3);

    $response = $this->get('/debug/games')->assertOk();
    $matchOptions = $response->original->getData()['page']['props']['matchOptions'];

    expect($matchOptions)->toHaveCount(3);
    expect($matchOptions[0])->toHaveKeys(['label', 'value']);
    expect($matchOptions[0]['label'])->toContain('TestOpponent');
});

it('loads debug matches page with bounded query count', function () {
    createMatchWithGamesAndOpponent(10);

    DB::enableQueryLog();
    $this->get('/debug/matches')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(25);
});
