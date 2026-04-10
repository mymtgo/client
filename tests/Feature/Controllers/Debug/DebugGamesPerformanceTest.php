<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

beforeEach(function () {
    Settings::set('debug_mode', true);
});

function createMatchWithGamesAndOpponent(int $count = 5): void
{
    $opponent = Player::firstOrCreate(['username' => 'TestOpponent']);

    for ($i = 0; $i < $count; $i++) {
        $match = MtgoMatch::factory()->create();
        $game = Game::factory()->create(['match_id' => $match->id]);
        $game->players()->attach($opponent->id, [
            'instance_id' => 1,
            'is_local' => false,
            'on_play' => false,
        ]);
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
