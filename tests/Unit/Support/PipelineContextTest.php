<?php

use App\Models\Account;
use App\Models\Card;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('caches a match lookup by token within a tick', function () {
    $match = MtgoMatch::factory()->create(['token' => 'abc-123']);

    $ctx = new PipelineContext;
    $first = $ctx->matchByToken('abc-123');
    $match->update(['token' => 'changed-token']);
    $second = $ctx->matchByToken('abc-123');

    expect($first->id)->toBe($match->id)
        ->and($second->id)->toBe($match->id);
});

it('forgets cached lookups when reset', function () {
    $match = MtgoMatch::factory()->create(['token' => 'abc']);

    $ctx = new PipelineContext;
    $ctx->matchByToken('abc');
    $match->delete();
    $ctx->reset();

    expect($ctx->matchByToken('abc'))->toBeNull();
});

it('caches account lookups by username', function () {
    $account = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);

    $ctx = new PipelineContext;
    $resolved = $ctx->accountByUsername('me');

    expect($resolved->id)->toBe($account->id);
});

it('caches oracle_id by multiverse id', function () {
    Card::factory()->create(['mtgo_id' => 12345, 'oracle_id' => 'oracle-abc']);

    $ctx = new PipelineContext;

    expect($ctx->oracleByMultiverseId(12345))->toBe('oracle-abc');
});

it('remembers local username via setter', function () {
    $ctx = new PipelineContext;
    $ctx->setLocalUsername('me');

    expect($ctx->localUsername())->toBe('me');
});

it('returns null for unknown lookups', function () {
    $ctx = new PipelineContext;

    expect($ctx->matchByToken('nope'))->toBeNull()
        ->and($ctx->accountByUsername('nobody'))->toBeNull()
        ->and($ctx->oracleByMultiverseId(99999))->toBeNull()
        ->and($ctx->localUsername())->toBeNull();
});
