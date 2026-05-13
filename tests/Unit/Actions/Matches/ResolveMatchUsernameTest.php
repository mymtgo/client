<?php

use App\Actions\Matches\ResolveMatchUsername;
use App\Facades\Mtgo;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('returns the username from the first event that carries one', function () {
    $events = new Collection([
        new LogEvent(['username' => null]),
        new LogEvent(['username' => 'LocalPlayer']),
        new LogEvent(['username' => 'AnotherPlayer']),
    ]);

    expect(ResolveMatchUsername::fromEvents($events))->toBe('LocalPlayer');
});

it('falls back to Mtgo::resolveUsername when no event carries a username', function () {
    $events = new Collection([
        new LogEvent(['username' => null]),
        new LogEvent(['username' => null]),
    ]);

    Mtgo::shouldReceive('resolveUsername')
        ->once()
        ->with(['Foo', 'Bar'])
        ->andReturn('Foo');

    expect(ResolveMatchUsername::fromEvents($events, ['Foo', 'Bar']))->toBe('Foo');
});

it('returns null when neither events nor facade resolve a username', function () {
    Mtgo::shouldReceive('resolveUsername')
        ->once()
        ->with([])
        ->andReturn(null);

    expect(ResolveMatchUsername::fromEvents(new Collection))->toBeNull();
});

it('resolves from LogEvent rows by match_token', function () {
    LogEvent::factory()->create([
        'match_token' => 'abc',
        'username' => 'LocalPlayer',
    ]);

    expect(ResolveMatchUsername::run('abc'))->toBe('LocalPlayer');
});

it('falls back to Mtgo facade when no LogEvent has a username for the token', function () {
    LogEvent::factory()->create([
        'match_token' => 'no-username-token',
        'username' => null,
    ]);

    Mtgo::shouldReceive('resolveUsername')
        ->once()
        ->withNoArgs()
        ->andReturn('FacadeFallback');

    expect(ResolveMatchUsername::run('no-username-token'))->toBe('FacadeFallback');
});
