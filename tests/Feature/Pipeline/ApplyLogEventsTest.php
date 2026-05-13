<?php

use App\Actions\Logs\IngestLog;
use App\Actions\Pipeline\ApplyLogEvents;
use App\Models\Account;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Walker resolves the local username via the Mtgo facade / Account row.
    // Seed the local account directly so the pipeline doesn't depend on a
    // running MTGO process or facade resolution.
    Account::query()->create([
        'username' => 'player_a',
        'tracked' => true,
        'active' => true,
    ]);

    $this->fixturePath = base_path('tests/Fixtures/Logs/sanitized-session.log');
});

it('processes the sanitised session into 3 matches with 7 games', function () {
    IngestLog::run($this->fixturePath);
    ApplyLogEvents::run();

    expect(MtgoMatch::count())->toBe(3)
        ->and(Game::count())->toBe(7);

    expect(MtgoMatch::where('token', '00000000-0000-0000-0000-000000000001')->first()?->games()->count())->toBe(2)
        ->and(MtgoMatch::where('token', '00000000-0000-0000-0000-000000000002')->first()?->games()->count())->toBe(2)
        ->and(MtgoMatch::where('token', '00000000-0000-0000-0000-000000000003')->first()?->games()->count())->toBe(3);
});

it('is idempotent — re-running produces no new rows', function () {
    IngestLog::run($this->fixturePath);
    ApplyLogEvents::run();

    $matchCount = MtgoMatch::count();
    $gameCount = Game::count();

    ApplyLogEvents::run();

    expect(MtgoMatch::count())->toBe($matchCount)
        ->and(Game::count())->toBe($gameCount);
});
