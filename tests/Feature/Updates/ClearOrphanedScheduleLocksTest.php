<?php

use App\Updates\ClearOrphanedScheduleLocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('releases orphaned scheduler mutex locks', function () {
    DB::table('cache_locks')->insert([
        'key' => 'mymtgo-cache-framework/schedule-deadbeef',
        'owner' => 'someowner',
        'expiration' => now()->addDay()->timestamp,
    ]);

    (new ClearOrphanedScheduleLocks)->run();

    expect(DB::table('cache_locks')->where('key', 'like', '%framework/schedule-%')->count())->toBe(0);
});

it('leaves non-schedule cache entries untouched', function () {
    DB::table('cache_locks')->insert([
        'key' => 'mymtgo-cache-framework/schedule-deadbeef',
        'owner' => 'someowner',
        'expiration' => now()->addDay()->timestamp,
    ]);
    DB::table('cache')->insert([
        'key' => 'mymtgo-cache-some_archetype',
        'value' => 'x',
        'expiration' => now()->addDay()->timestamp,
    ]);

    (new ClearOrphanedScheduleLocks)->run();

    expect(DB::table('cache_locks')->count())->toBe(0)
        ->and(DB::table('cache')->where('key', 'mymtgo-cache-some_archetype')->count())->toBe(1);
});
