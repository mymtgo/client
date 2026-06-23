<?php

use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an opponent with a username', function () {
    $opponent = Opponent::factory()->create(['username' => 'goblin_guide']);

    expect($opponent->username)->toBe('goblin_guide');
    expect(Opponent::count())->toBe(1);
});

it('enforces a unique username', function () {
    Opponent::factory()->create(['username' => 'dup']);
    Opponent::factory()->create(['username' => 'dup']);
})->throws(QueryException::class);

it('has many matches via opponent_id', function () {
    $opponent = Opponent::factory()->create();
    MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);

    expect($opponent->matches)->toHaveCount(1);
});
