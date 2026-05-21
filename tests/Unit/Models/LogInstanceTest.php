<?php

use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('reports unsealed by default', function () {
    $instance = LogInstance::factory()->create();
    expect($instance->isSealed())->toBeFalse();
});

it('reports sealed when sealed_at is set', function () {
    $instance = LogInstance::factory()->sealed('truncated')->create();

    expect($instance->isSealed())->toBeTrue()
        ->and($instance->seal_reason)->toBe('truncated');
});
