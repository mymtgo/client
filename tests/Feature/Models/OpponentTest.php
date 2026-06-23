<?php

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
